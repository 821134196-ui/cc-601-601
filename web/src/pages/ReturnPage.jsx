import { useEffect, useState } from 'react'
import { api, fmtFee, fmtTime, ORDER_STATUS } from '../api.js'

/** 还伞:查空位 → 选站开门 → 入柜+关门双确认 → 计费 → 支付。满柜可换站,计费起点不变 */
export default function ReturnPage() {
  const [stations, setStations] = useState([])
  const [userName, setUserName] = useState('')
  const [orders, setOrders] = useState([])
  const [orderNo, setOrderNo] = useState('')
  const [stationCode, setStationCode] = useState('')
  const [session, setSession] = useState(null)
  const [order, setOrder] = useState(null)
  const [alternatives, setAlternatives] = useState(null)
  const [error, setError] = useState('')
  const [payInfo, setPayInfo] = useState(null)

  const loadStations = () => api.get('/stations').then((d) => setStations(d.stations)).catch(() => {})

  useEffect(() => { loadStations() }, [])

  // 按称呼查进行中的订单
  const findOrders = async () => {
    setError('')
    try {
      const data = await api.get(`/orders?user_name=${encodeURIComponent(userName)}`)
      const active = data.orders.filter((o) => ['borrowed', 'returning'].includes(o.status))
      setOrders(active)
      if (active.length > 0) setOrderNo(active[0].order_no)
      if (active.length === 0) setError('没有找到进行中的借伞订单')
    } catch (e) { setError(e.message) }
  }

  // 创建还伞会话;满柜时展示备选站点
  const createSession = async (targetStation) => {
    setError('')
    setAlternatives(null)
    try {
      const data = await api.post('/returns', { order_no: orderNo, station_code: targetStation })
      setSession(data.session)
      setStationCode(targetStation)
      loadStations()
    } catch (e) {
      if (e.payload?.error === 'station_full') {
        setAlternatives(e.payload.alternatives ?? [])
      }
      setError(e.message)
    }
  }

  // 会话进行中轮询订单,等待入柜+关门确认
  useEffect(() => {
    if (!session || order?.status === 'completed') return
    const timer = setInterval(async () => {
      try {
        const [s, o] = await Promise.all([
          api.get(`/returns/${session.session_no}`),
          api.get(`/orders/${session.order_no}`),
        ])
        setSession(s.session)
        setOrder(o.order)
      } catch { /* 忽略瞬时错误 */ }
    }, 1000)
    return () => clearInterval(timer)
  }, [session, order])

  const pay = async () => {
    if (!order?.payment) return
    const data = await api.post(`/payments/${order.payment.payment_no}/mock-pay`, {})
    setPayInfo(data)
    const o = await api.get(`/orders/${order.order_no}`)
    setOrder(o.order)
  }

  const reset = () => {
    setSession(null); setOrder(null); setAlternatives(null); setError(''); setPayInfo(null)
    setOrders([]); setOrderNo('')
    loadStations()
  }

  const currentOrder = order ?? orders.find((o) => o.order_no === orderNo)

  return (
    <>
      <h2>还伞</h2>

      {!session && (
        <div className="card" style={{ maxWidth: 480 }}>
          <label>您的称呼(查询进行中的订单)</label>
          <div className="row">
            <input value={userName} onChange={(e) => setUserName(e.target.value)} placeholder="借伞时填写的称呼" />
            <button className="shrink ghost" onClick={findOrders} disabled={!userName.trim()}>查询</button>
          </div>

          {orders.length > 0 && (
            <>
              <label>选择要归还的订单</label>
              <select value={orderNo} onChange={(e) => setOrderNo(e.target.value)}>
                {orders.map((o) => (
                  <option key={o.order_no} value={o.order_no}>
                    {o.umbrella_code} · 借于 {o.borrow_station} {fmtTime(o.borrowed_at)}
                  </option>
                ))}
              </select>

              <label>归还站点(显示实时空位数)</label>
              <select value={stationCode} onChange={(e) => setStationCode(e.target.value)}>
                <option value="">请选择</option>
                {stations.map((s) => (
                  <option key={s.code} value={s.code} disabled={s.empty_slots === 0}>
                    {s.name} — 空位 {s.empty_slots}{s.empty_slots === 0 ? '(已满)' : ''}
                  </option>
                ))}
              </select>

              <div className="mt">
                <button disabled={!orderNo || !stationCode} onClick={() => createSession(stationCode)}>
                  打开空柜门
                </button>
              </div>
            </>
          )}

          {error && <div className="notice error">{error}</div>}

          {alternatives && (
            <div className="notice warn">
              该站已满,可改到以下站点归还(计费起点不变):
              <div className="mt row">
                {alternatives.map((a) => (
                  <button key={a.code} className="small shrink" onClick={() => createSession(a.code)}>
                    {a.name}(空位 {a.empty_slots})
                  </button>
                ))}
              </div>
            </div>
          )}
        </div>
      )}

      {session && (
        <div className="card" style={{ maxWidth: 480 }}>
          <h3>还伞会话 <span className="mono">{session.session_no}</span></h3>
          <ul className="steps">
            <li className="done">柜门 {session.slot_label} 已打开({session.station_name})</li>
            <li className={session.inserted_at ? 'done' : 'active'}>
              {session.inserted_at ? '设备已确认雨伞入柜' : '请放入雨伞…'}
            </li>
            <li className={session.door_closed_at ? 'done' : (session.inserted_at ? 'active' : '')}>
              {session.door_closed_at ? '柜门已关闭' : '请关好柜门…'}
            </li>
            <li className={order?.status === 'completed' ? 'done' : ''}>归还确认,生成账单</li>
          </ul>

          {order?.status !== 'completed' && (
            <div className="notice info">归还以"雨伞入柜 + 柜门关闭"双重确认为准,请稍候…</div>
          )}

          {order?.status === 'completed' && (
            <>
              <div className="notice ok">
                归还成功!计费 {fmtTime(order.borrowed_at)} → {fmtTime(order.returned_at)},
                费用 <b>{fmtFee(order.fee_cents)}</b>
              </div>
              {order.payment?.status === 'paid' ? (
                <div className="notice ok">已支付 {fmtFee(order.payment.amount_cents)} ✓</div>
              ) : (
                <button onClick={pay}>模拟支付 {fmtFee(order.payment?.amount_cents)}</button>
              )}
              {payInfo && (
                <div className="muted mt">
                  回执 {payInfo.receipt_no}:网关推送 {payInfo.deliveries.length} 次,
                  入账 {payInfo.deliveries.filter((d) => d.result === 'applied').length} 次(重复推送自动去重)
                </div>
              )}
              <div className="mt"><button className="ghost" onClick={reset}>完成</button></div>
            </>
          )}
        </div>
      )}

      {currentOrder && !session && (
        <div className="muted mt">订单状态:{ORDER_STATUS[currentOrder.status]}</div>
      )}
    </>
  )
}
