# 社区共享雨伞归还点容量管理系统

居民跨站借还雨伞：借前查附近哪里有伞，还前查哪里有空柜位。订单状态与柜位占用
完全由柜机实际事件驱动（柜门放伞 / 关门确认入柜），离线补传、重复归还、重复
支付回执均不会破坏位置、计费与柜位数量的一致性。

## 一键启动

```bash
./start.sh          # 自动准备 PHP 运行时、依赖、前端构建、数据库，然后启动服务
```

打开 http://127.0.0.1:8000 ：

| 页面 | 功能 |
|---|---|
| 站点借还 | 各站点可借伞数/空柜位，借伞、还伞（满柜时推荐其他站）、支付 |
| 柜机模拟 | 模拟柜机取伞/放伞关门、断网离线、恢复在线后补传积压事件 |
| 管理后台 | 按伞号查位置、清洁/维修/丢失处置、订单/事件/支付记录核对 |

运行一致性验证测试（16 个用例）：

```bash
cd server && ../../.runtime/php artisan test
```

## 技术栈

- 后端：PHP 8.4 + Laravel 13，Eloquent ORM + SQLite（`server/database/database.sqlite`）
- 前端：React 18 + Vite，构建产物由 Laravel 统一伺服（`server/public/app/`）
- 柜机与支付：本地模拟（`SimCabinet` 服务 + `/api/cabinet/*`、`/api/payments/*`）

## 一致性设计（核心）

**事件驱动状态机。** 借伞下单只预留柜位并开门；柜机上报 `umbrella_dispensed`
（柜门实际放伞）订单才生效，**计费起点 = 事件发生时间**。归还同理：柜门关闭
且设备确认入柜（`umbrella_returned`）订单才完成，费用在此时一次性核定。

**幂等事件处理**（`app/Services/EventProcessor.php`）：

- 每个柜机事件带全局唯一 `event_uid`（数据库唯一索引）。网络重试、离线补传
  重发同一事件 → 判重忽略，状态不变；
- 事件落库与状态迁移在同一事务内完成；
- 状态迁移带前置校验（订单须处于期望状态、柜位须为本订单预留），即使柜机
  故障用**新** event_uid 重发同一物理动作，也只会记录为 `rejected`，不会
  多释放/多占用一个柜位、不会改变费用。

**离线补传。** 柜机离线时事件暂存柜机本地队列（`sim_outbox` 表模拟），恢复
在线后按发生时间排序批量补传（`/api/device/events/batch`），逐条返回处理
结果供柜机端核对；管理后台"柜机事件记录"可复查每条事件的处理结果。

**支付幂等。** 支付回执以 `transaction_id` 为幂等键（唯一索引）：重复回执
返回原记录不重复收费；已支付订单收到新交易号、金额与核定费用不符，均拒绝。

**数量始终由状态推导。** 站点页的可借数/空位数实时由柜位与雨伞状态计算
（不维护缓存计数），因此页面展示必然与柜机事件处理结果一致。维修/清洁中
的伞物理上仍占柜位，但不计入可借数，借伞也不会被选中。

## 主要 API

```
GET  /api/stations                     站点列表（可借数/空位数/柜机在线状态）
POST /api/orders                       发起借伞 {station_id, user_name}
POST /api/orders/{id}/return           发起还伞 {station_id}；满柜返回 409 + 可选站点
POST /api/orders/{id}/cancel-return    取消归还（改去别的站，计费起点保留）
POST /api/cabinet/{st}/take|place      柜机模拟：取伞 / 放伞关门
POST /api/cabinet/{st}/online          柜机上线/离线
POST /api/cabinet/{st}/sync            补传离线积压事件
POST /api/device/events[/batch]        柜机事件上报 / 批量补传（幂等）
POST /api/payments/checkout|confirm    模拟支付收银台 / 回执回调（幂等）
GET  /api/admin/umbrella?code=UMB-0001 按伞号查位置
PATCH /api/admin/umbrellas/{id}        清洁/维修/丢失/恢复
```

## 验证场景（对应测试）

| 场景 | 测试 |
|---|---|
| 满柜换站，原计费起点保留 | `tests/Feature/FullStationSwitchTest.php` |
| 离线事件补传、重复补传判重 | `tests/Feature/OfflineBackfillTest.php` |
| 重复归还/重复放伞不多占柜位 | `tests/Feature/DuplicateEventTest.php` |
| 重复支付回执不重复收费 | `tests/Feature/PaymentIdempotencyTest.php` |
| 维修不可借、全局数量不变量 | `tests/Feature/RepairAndConsistencyTest.php` |

## 目录结构

```
start.sh                 一键启动
server/                  Laravel 后端
  app/Models/            Station / Slot / Umbrella / Order / DeviceEvent / Payment / SimOutbox
  app/Services/          UmbrellaService（借还编排）/ EventProcessor（幂等事件）
                         SimCabinet（柜机模拟）/ PaymentService / PricingService
  database/migrations/   核心表结构（event_uid、transaction_id 唯一索引）
  tests/Feature/         一致性验证测试
web/                     React 前端（Vite），构建到 server/public/app
```
