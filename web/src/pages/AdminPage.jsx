import React, { useCallback, useEffect, useState } from 'react'
import { api, fmtFee, ORDER_STATUS, UMBRELLA_STATUS } from '../api.js'

export default function AdminPage() {
  const [code, setCode] = useState('')
  const [found, setFound] = useState(null)
  const [findErr, setFindErr] = useState(null)
  const [umbrellas, setUmbrellas] = useState([])
  const [orders, setOrders] = useState([])
  const [events, setEvents] = useState([])
  const [payments, setPayments] = useState([])

  const refresh = useCallback(async () => {
    const [u, o, e, p] = await Promise.all([
      api.get('/admin/umbrellas'),
      api.get('/admin/orders'),
      api.get('/device/events'),
      api.get('/payments'),
    ])
    setUmbrellas(u)
    setOrders(o)
    setEvents(e)
    setPayments(p)
  }, [])

  useEffect(() => {
    refresh()
    const t = setInterval(refresh, 5000)
    return () => clearInterval(t)
  }, [refresh])

  const find = async () => {
    setFindErr(null)
    setFound(null)
    try {
      setFound(await api.get(`/admin/umbrella?code=${encodeURIComponent(code)}`))
    } catch (e) {
      setFindErr(e.message)
    }
  }

  const setStatus = async (id, status) => {
    try {
      await api.patch(`/admin/umbrellas/${id}`, { status })
      await refresh()
    } catch (e) {
      alert(e.message)
    }
  }

  return (
    <div>
      <h2>按伞号查位置</h2>
      <div className="card">
        <input value={code} placeholder="如 UMB-0001" onChange={(e) => setCode(e.target.value)} />
        <button onClick={find} disabled={!code}>查询</button>
        {findErr && <span className="notice err inline">{findErr}</span>}
        {found && (
          <div className="found">
            <b>{found.umbrella.code}</b>（{UMBRELLA_STATUS[found.umbrella.status]}）当前位置：
            <b>{found.location}</b>
            {found.active_order && (
              <div className="muted">
                关联订单 {found.active_order.order_no}（{found.active_order.user_name}，
                {ORDER_STATUS[found.active_order.status]}，借于 {found.active_order.borrow_station}）
              </div>
            )}
          </div>
        )}
      </div>

      <h2>雨伞处置</h2>
      <table>
        <thead>
          <tr><th>伞号</th><th>状态</th><th>位置</th><th>操作</th></tr>
        </thead>
        <tbody>
          {umbrellas.map((u) => (
            <tr key={u.id}>
              <td>{u.code}</td>
              <td><span className={`badge st-${u.status}`}>{UMBRELLA_STATUS[u.status]}</span></td>
              <td>{u.location}</td>
              <td className="ops">
                {u.status === 'in_slot' && (
                  <>
                    <button className="link" onClick={() => setStatus(u.id, 'cleaning')}>清洁</button>
                    <button className="link" onClick={() => setStatus(u.id, 'repair')}>维修</button>
                  </>
                )}
                {(u.status === 'cleaning' || u.status === 'repair') && (
                  <button className="link" onClick={() => setStatus(u.id, 'in_slot')}>恢复可用</button>
                )}
                {u.status !== 'lost' && (
                  <button className="link danger" onClick={() => setStatus(u.id, 'lost')}>标记丢失</button>
                )}
              </td>
            </tr>
          ))}
        </tbody>
      </table>

      <h2>订单记录</h2>
      <table>
        <thead>
          <tr><th>订单号</th><th>用户</th><th>伞号</th><th>状态</th><th>借出站点</th><th>归还站点</th><th>计费起点</th><th>费用</th><th>支付</th></tr>
        </thead>
        <tbody>
          {orders.map((o) => (
            <tr key={o.id}>
              <td>{o.order_no}</td>
              <td>{o.user_name}</td>
              <td>{o.umbrella?.code}</td>
              <td>{ORDER_STATUS[o.status]}</td>
              <td>{o.borrow_station?.name}</td>
              <td>{o.return_station?.name ?? '—'}</td>
              <td>{o.borrowed_at ? new Date(o.borrowed_at).toLocaleString() : '—'}</td>
              <td>{fmtFee(o.fee_cents)}</td>
              <td>{o.payment_status === 'paid' ? '已支付' : '未支付'}</td>
            </tr>
          ))}
        </tbody>
      </table>

      <h2>柜机事件记录（补传核对）</h2>
      <table>
        <thead>
          <tr><th>事件 ID</th><th>类型</th><th>站点</th><th>处理结果</th><th>发生时间</th></tr>
        </thead>
        <tbody>
          {events.map((e) => (
            <tr key={e.id}>
              <td className="mono">{e.event_uid.slice(0, 26)}…</td>
              <td>{e.type === 'umbrella_dispensed' ? '放出雨伞' : '确认入柜'}</td>
              <td>#{e.station_id}</td>
              <td>{e.result === 'processed' ? '✅ 已处理' : `⚠️ ${e.result}`}</td>
              <td>{new Date(e.occurred_at).toLocaleString()}</td>
            </tr>
          ))}
        </tbody>
      </table>

      <h2>支付记录</h2>
      <table>
        <thead>
          <tr><th>交易号</th><th>订单</th><th>金额</th><th>状态</th><th>时间</th></tr>
        </thead>
        <tbody>
          {payments.map((p) => (
            <tr key={p.id}>
              <td className="mono">{p.transaction_id}</td>
              <td>#{p.order_id}</td>
              <td>{fmtFee(p.amount_cents)}</td>
              <td>{p.status}</td>
              <td>{new Date(p.created_at).toLocaleString()}</td>
            </tr>
          ))}
        </tbody>
      </table>
    </div>
  )
}
