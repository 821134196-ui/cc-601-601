import { useEffect, useRef, useState } from 'react'
import { api, fmtTime, ORDER_STATUS } from '../api.js'

/** 借伞:选站下单 → 柜门放出雨伞(设备事件确认)→ 开始计费 */
export default function BorrowPage() {
  const [stations, setStations] = useState([])
  const [stationCode, setStationCode] = useState('')
  const [userName, setUserName] = useState('')
  const [order, setOrder] = useState(null)
  const [slotLabel, setSlotLabel] = useState('')
  const [error, setError] = useState('')
  const [busy, setBusy] = useState(false)
  const pollRef = useRef(null)

  useEffect(() => {
    api.get('/stations').then((d) => {
      setStations(d.stations)
      const first = d.stations.find((s) => s.available_umbrellas > 0)
      if (first) setStationCode((c) => c || first.code)
    }).catch(() => {})
  }, [])

  // 订单处于 dispensing 时轮询,等柜门实际放出雨伞
  useEffect(() => {
    if (!order || order.status !== 'dispensing') return
    pollRef.current = setInterval(async () => {
      try {
        const data = await api.get(`/orders/${order.order_no}`)
        setOrder(data.order)
      } catch { /* 忽略瞬时错误 */ }
    }, 1000)
    return () => clearInterval(pollRef.current)
  }, [order])

  const submit = async () => {
    setBusy(true)
    setError('')
    try {
      const data = await api.post('/orders', { user_name: userName, station_code: stationCode })
      setOrder(data.order)
      setSlotLabel(data.slot_label)
    } catch (e) {
      setError(e.message)
    } finally {
      setBusy(false)
    }
  }

  const reset = () => { setOrder(null); setSlotLabel(''); setError('') }

  return (
    <>
      <h2>借伞</h2>
      {!order && (
        <div className="card" style={{ maxWidth: 420 }}>
          <label>取伞站点(显示实时可借数)</label>
          <select value={stationCode} onChange={(e) => setStationCode(e.target.value)}>
            {stations.map((s) => (
              <option key={s.code} value={s.code} disabled={s.available_umbrellas === 0}>
                {s.name} — 可借 {s.available_umbrellas} 把{s.available_umbrellas === 0 ? '(暂无可借)' : ''}
              </option>
            ))}
          </select>
          <label>您的称呼</label>
          <input value={userName} onChange={(e) => setUserName(e.target.value)} placeholder="例如:张女士" />
          <div className="mt">
            <button disabled={busy || !userName.trim() || !stationCode} onClick={submit}>
              {busy ? '开柜中…' : '开柜取伞'}
            </button>
          </div>
          {error && <div className="notice error">{error}</div>}
        </div>
      )}

      {order && (
        <div className="card" style={{ maxWidth: 480 }}>
          <h3>订单 <span className="mono">{order.order_no}</span></h3>
          <ul className="steps">
            <li className="done">已分配雨伞 {order.umbrella_code},柜门 {slotLabel}</li>
            <li className={order.status === 'dispensing' ? 'active' : 'done'}>
              {order.status === 'dispensing' ? '柜门已开,请取走雨伞…' : '柜门已放出雨伞(设备确认)'}
            </li>
            <li className={order.status === 'borrowed' ? 'done' : ''}>
              开始计费{order.borrowed_at ? `:${fmtTime(order.borrowed_at)}` : ''}
            </li>
          </ul>

          {order.status === 'dispensing' && <div className="notice info">等待柜机确认放伞,借用以柜门实际放出为准…</div>}
          {order.status === 'borrowed' && (
            <div className="notice ok">
              借伞成功!伞号 {order.umbrella_code},计费起点 {fmtTime(order.borrowed_at)}。
              可在任意站点归还,归还前请在"还伞"页查询空柜位。
            </div>
          )}
          {order.status === 'cancelled' && <div className="notice warn">订单已取消(取伞超时),雨伞未放出。</div>}
          {order.status !== 'dispensing' && <button className="ghost" onClick={reset}>再借一把</button>}
          <div className="muted mt">当前状态:{ORDER_STATUS[order.status]}</div>
        </div>
      )}
    </>
  )
}
