import { useEffect, useState } from 'react'
import { api, UMBRELLA_STATUS } from '../api.js'

/** 站点总览:各站可借雨伞数与空柜位数,每 3 秒自动刷新 */
export default function StationsPage() {
  const [stations, setStations] = useState([])
  const [selected, setSelected] = useState(null)
  const [detail, setDetail] = useState(null)

  useEffect(() => {
    let timer
    const load = async () => {
      try {
        const data = await api.get('/stations')
        setStations(data.stations)
      } catch { /* 服务端未就绪时静默重试 */ }
      timer = setTimeout(load, 3000)
    }
    load()
    return () => clearTimeout(timer)
  }, [])

  useEffect(() => {
    if (!selected) return
    let timer
    const load = async () => {
      const data = await api.get(`/stations/${selected}`)
      setDetail(data)
      timer = setTimeout(load, 3000)
    }
    load().catch(() => {})
    return () => clearTimeout(timer)
  }, [selected])

  return (
    <>
      <h2>站点实时容量</h2>
      <div className="grid">
        {stations.map((s) => (
          <div className="card" key={s.code} onClick={() => setSelected(s.code)} style={{ cursor: 'pointer' }}>
            <h3>
              {s.name}{' '}
              <span className={`badge ${s.online ? 'on' : 'off'}`}>{s.online ? '在线' : '离线'}</span>
            </h3>
            <div className="addr">{s.address}</div>
            <div className="stats">
              <div className="stat ok"><b>{s.available_umbrellas}</b><span>可借雨伞</span></div>
              <div className="stat"><b>{s.empty_slots}</b><span>空柜位</span></div>
              <div className="stat warn"><b>{s.maintenance}</b><span>维护中</span></div>
              <div className="stat"><b>{s.total_slots}</b><span>总柜位</span></div>
            </div>
          </div>
        ))}
      </div>

      {detail && (
        <>
          <h2>{detail.station.name} · 柜位明细</h2>
          <div className="card">
            <div className="slots">
              {detail.slots.map((slot) => (
                <div
                  key={slot.label}
                  className={`slot ${slot.state === 'empty' ? (slot.reserved ? 'reserved' : 'empty') : (slot.umbrella && slot.umbrella.status !== 'in_slot' ? 'maintenance' : 'occupied')}`}
                >
                  <div className="label">{slot.label}</div>
                  {slot.state === 'empty'
                    ? (slot.reserved ? '已预留' : '空位')
                    : slot.umbrella
                      ? <>{slot.umbrella.code.replace('UMB-', '#')}<br />{UMBRELLA_STATUS[slot.umbrella.status]}</>
                      : '占用'}
                </div>
              ))}
            </div>
          </div>
        </>
      )}
    </>
  )
}
