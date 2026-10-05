import React, { useCallback, useEffect, useState } from 'react'
import { api, fmtFee, ORDER_STATUS } from '../api.js'

export default function StationsPage() {
  const [user, setUser] = useState(() => localStorage.getItem('umbrella_user') || '')
  const [stations, setStations] = useState([])
  const [orders, setOrders] = useState([])
  const [notice, setNotice] = useState(null)

  const refresh = useCallback(async () => {
    setStations(await api.get('/stations'))
    if (user) setOrders(await api.get(`/orders?user=${encodeURIComponent(user)}`))
  }, [user])

  useEffect(() => {
    refresh()
    const t = setInterval(refresh, 3000)
    return () => clearInterval(t)
  }, [refresh])

  const saveUser = (name) => {
    setUser(name)
    localStorage.setItem('umbrella_user', name)
  }

  const run = async (fn, okMsg) => {
    setNotice(null)
    try {
      await fn()
      if (okMsg) setNotice({ type: 'ok', text: okMsg })
      await refresh()
    } catch (e) {
      setNotice({ type: 'err', text: e.message })
    }
  }

  const borrow = (stationId) =>
    run(() => api.post('/orders', { station_id: stationId, user_name: user }),
      '柜门已打开，请到柜机取伞（模拟页可模拟取伞动作）')

  return (
    <div>
      <section className="card">
        <label>我的姓名：</label>
        <input
          value={user}
          placeholder="输入姓名后借还伞"
          onChange={(e) => saveUser(e.target.value)}
        />
      </section>

      {notice && <div className={`notice ${notice.type}`}>{notice.text}</div>}

      <h2>附近站点</h2>
      <div className="grid">
        {stations.map((s) => (
          <div key={s.id} className="card station">
            <div className="station-head">
              <strong>{s.name}</strong>
              <span className={s.cabinet_online ? 'badge online' : 'badge offline'}>
                {s.cabinet_online ? '柜机在线' : '柜机离线'}
              </span>
            </div>
            <div className="addr">{s.address}</div>
            <div className="counts">
              <span className="count">可借 <b>{s.available_umbrellas}</b></span>
              <span className="count">空位 <b>{s.empty_slots}</b> / {s.total_slots}</span>
            </div>
            <button
              disabled={!user || s.available_umbrellas === 0}
              onClick={() => borrow(s.id)}
            >
              {s.available_umbrellas === 0 ? '暂无可借' : '借一把伞'}
            </button>
          </div>
        ))}
      </div>

      <h2>我的订单</h2>
      {orders.length === 0 && <p className="muted">暂无订单</p>}
      {orders.map((o) => (
        <OrderCard key={o.id} order={o} stations={stations} run={run} refresh={refresh} />
      ))}
    </div>
  )
}

function OrderCard({ order: o, stations, run, refresh }) {
  const [returnStation, setReturnStation] = useState('')
  const [fullInfo, setFullInfo] = useState(null)
  const [paying, setPaying] = useState(false)

  const doReturn = async (stationId) => {
    setFullInfo(null)
    try {
      await api.post(`/orders/${o.id}/return`, { station_id: Number(stationId) })
      await refresh()
    } catch (e) {
      if (e.status === 409 && e.data?.station_full) {
        setFullInfo(e.data)
      } else {
        alert(e.message)
      }
    }
  }

  const pay = async () => {
    setPaying(true)
    try {
      const txn = await api.post('/payments/checkout', { order_id: o.id })
      const r = await api.post('/payments/confirm', {
        order_id: o.id,
        transaction_id: txn.transaction_id,
        amount_cents: txn.amount_cents,
      })
      alert(r.duplicate ? '重复回执，已忽略（不会重复收费）' : '支付成功')
      await refresh()
    } catch (e) {
      alert(e.message)
    } finally {
      setPaying(false)
    }
  }

  return (
    <div className={`card order status-${o.status}`}>
      <div className="station-head">
        <strong>{o.order_no}</strong>
        <span className="badge">{ORDER_STATUS[o.status]}</span>
      </div>
      <div className="muted">
        伞号 {o.umbrella_code} ｜ 借出站点 {o.borrow_station?.name}
        {o.return_station && ` ｜ 归还站点 ${o.return_station.name}`}
      </div>
      <div className="muted">
        计费起点 {o.borrowed_at ? new Date(o.borrowed_at).toLocaleString() : '（待柜门放伞）'}
        {o.returned_at && ` ｜ 归还时间 ${new Date(o.returned_at).toLocaleString()}`}
        {o.status === 'completed' && ` ｜ 费用 ${fmtFee(o.fee_cents)}（${o.payment_status === 'paid' ? '已支付' : '待支付'}）`}
      </div>

      {o.status === 'dispensing' && (
        <div className="actions">
          <span className="muted">柜门已开，请到柜机取伞；超时未取可取消：</span>
          <button className="link" onClick={() => run(() => api.post(`/orders/${o.id}/cancel-borrow`), '已取消')}>取消借伞</button>
        </div>
      )}

      {o.status === 'active' && (
        <div className="actions">
          <select value={returnStation} onChange={(e) => setReturnStation(e.target.value)}>
            <option value="">选择归还站点…</option>
            {stations.map((s) => (
              <option key={s.id} value={s.id}>
                {s.name}（空位 {s.empty_slots}）
              </option>
            ))}
          </select>
          <button disabled={!returnStation} onClick={() => doReturn(returnStation)}>还到这里</button>
        </div>
      )}

      {fullInfo && (
        <div className="notice err">
          {fullInfo.message}，可改去：
          {fullInfo.alternatives.map((a) => (
            <button key={a.id} className="link" onClick={() => doReturn(a.id)}>
              {a.name}（空位 {a.empty_slots}）
            </button>
          ))}
          <span className="muted">原计费起点继续保留，不受影响。</span>
        </div>
      )}

      {o.status === 'returning' && (
        <div className="actions">
          <span className="muted">柜门已开，请放伞并关门；设备确认后订单完成。</span>
          <button className="link" onClick={() => run(() => api.post(`/orders/${o.id}/cancel-return`), '已取消本次归还，可重新选择站点')}>取消归还</button>
        </div>
      )}

      {o.status === 'completed' && o.payment_status === 'unpaid' && (
        <div className="actions">
          <button disabled={paying} onClick={pay}>支付 {fmtFee(o.fee_cents)}</button>
        </div>
      )}
    </div>
  )
}
