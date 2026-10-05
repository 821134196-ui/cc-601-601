# 社区共享雨伞归还点容量管理系统

居民跨站借还雨伞:借前查各站可借数量,还前查各站空柜位;管理员按伞号定位雨伞并处理清洁、维修与丢失。
系统以**柜机设备事件**为唯一事实来源驱动订单状态与柜位占用,保证位置、计费、柜位数量三者始终一致。

## 一键启动

```bash
./start.sh           # 首次会自动安装依赖、迁移并播种演示数据
./start.sh --fresh   # 清空数据库重来
```

启动后:

| 入口 | 地址 |
|---|---|
| 前端(站点 / 借伞 / 还伞 / 管理) | http://127.0.0.1:5173 |
| 后端 API | http://127.0.0.1:8000/api |

`start.sh` 同时拉起三个进程:Laravel API(`php artisan serve`)、柜机模拟器(`php artisan cabinet:run`)、Vite 开发服务器。Ctrl+C 全部停止。

> 仓库内 `.runtime/` 自带静态编译的 PHP 8.4 + Composer,无需系统 PHP;若系统已有 PHP 8.2+ 与 Composer 则会自动改用系统版本。

## 运行测试

```bash
cd server && ../../.runtime/php artisan test   # 或系统 php:php artisan test
```

16 个特性测试覆盖:事件驱动借还、满柜换站、离线补传去重、重复归还、重复支付回执、维修伞不可借、三方对账。

> 注意:测试使用 SQLite 内存库。若手动执行过 `php artisan config:cache`
> (缓存的配置会覆盖 phpunit.xml 里的内存库设置),请先 `php artisan config:clear` 再跑测试。

## 架构

```
web/     React 19 + Vite(页面:站点总览 / 借伞 / 还伞 / 管理)
server/  Laravel 13 + Eloquent + SQLite
         ├─ API 服务层:OrderService / ReturnService / EventIngestionService
         │              PaymentService / ExpirationService / ReconcileService
         └─ cabinet:run 柜机模拟器(长驻进程,经 HTTP 与 API 交互)
```

### 事件驱动的状态机(核心)

页面上的一切状态都由设备事件确认,而不是由用户操作直接改写:

- **借伞**:`POST /api/orders` 只创建 `dispensing` 订单并下发开门命令;柜机上报
  `umbrella_dispensed`(柜门实际放出雨伞)后订单才变为 `borrowed`,
  **计费起点 = 该事件的设备时间**。
- **还伞**:`POST /api/returns` 在目标站预留空柜位;柜机上报 `umbrella_inserted`
  (设备确认入柜)**且** `door_closed`(柜门关闭)两个事件后归还才成立,
  计费终点取两事件中较晚者,随后生成支付单(一单一付)。

### 一致性设计

| 问题 | 机制 |
|---|---|
| 重复事件(网络重试/离线重传) | `device_events.event_uid` 唯一约束,重复投递直接返回已记录结果 |
| 柜机离线补传 | 事件带 `device_seq` 与设备时间,批量接口按序号排序逐条应用;冲突事件记为 `conflict` 留痕核对,不产生副作用 |
| 重复归还 | 雨伞已在柜 → `duplicate`;已完成订单再还 → 409;同站重复请求复用同一会话,不会多占柜位 |
| 重复支付回执 | `payments.order_id` 唯一(一单一付)+ `payment_receipts.receipt_no` 唯一,重复回执记 `duplicate`,不再收费 |
| 维修/清洁中的伞 | 状态机排除在分配之外(`in_slot` 才可借) |
| 满柜换站 | 409 返回有空位的备选站;换站创建新会话,订单 `borrowed_at` 不变 |
| 超时 | 借伞命令 120s 未放出 → 订单取消、伞恢复在架;还伞会话 600s 未完成 → 释放柜位预留 |
| 对账 | `GET /api/admin/reconcile` 核对柜位↔雨伞镜像、订单↔雨伞状态、会话↔柜位预留 |

### 柜机模拟器

`php artisan cabinet:run` 模拟真实柜机的 store-and-forward 行为:

- 轮询 `GET /api/device/poll` 领取开门命令,按真实时序产生事件
  (借伞:开门→取伞;还伞:开门→入柜→关门);
- 管理端把柜机切为**离线**后,事件写入本地 outbox(`storage/app/simulator/*.json`);
- 恢复在线后,outbox 事件经 `POST /api/device/events/batch` 批量补传,服务端核对去重。

支付同样本地模拟:`POST /api/payments/{no}/mock-pay`(可带 `times=N` 模拟网关重试)
→ 网关把回执推送到 `POST /api/webhooks/payment`。

## 手动验证三个关键场景

1. **满柜换站**:还伞页选择已满站点 → 页面给出有空位的备选站 → 换站归还成功,
   账单仍按原借用时间起算。
2. **离线补传**:管理页把某柜机切为离线 → 在该站完成一次借/还(事件被缓存)→
   恢复在线 → 事件批量补传,管理页"设备事件记录"可见逐条 `applied`;
   重复补传全部 `duplicate`,柜位数量不变。
3. **重复归还/重复回执**:归还完成后设备重发同一批事件 → 全部判重;
   支付时 `times=3` → 回执入账一次、判重两次,金额不变。管理页"对账"始终显示三方一致。

## 主要 API

```
GET  /api/stations                     各站可借数/空位数
GET  /api/stations/{code}              柜位级明细
POST /api/orders                       借伞 {user_name, station_code}
GET  /api/orders/{orderNo}             订单状态(前端轮询)
POST /api/returns                      还伞 {order_no, station_code}(满柜 409 + alternatives)
POST /api/payments/{no}/mock-pay       模拟支付 {times?}
POST /api/webhooks/payment             支付回执(幂等)
POST /api/device/events[/batch]        设备事件上报 / 离线补传
GET  /api/admin/umbrellas/{code}       按伞号定位
POST /api/admin/umbrellas/{code}/action  清洁/维修/恢复/丢失
POST /api/admin/cabinets/{code}/connectivity  柜机离线/上线
GET  /api/admin/reconcile              三方对账
GET  /api/admin/events                 设备事件记录
```
