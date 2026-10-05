import React, { useCallback, useEffect, useState } from 'react'
import { api } from '../api.js'

/** 柜机模拟面板：模拟柜机的物理动作与离线/在线行为 */
export default function CabinetPage() {
  const [stations, setStations] = useState([])
  const [selected, setSelected] = useState(null)
  const [state, setState] = useState(null)
  const [log, setLog] = useState([])

  const refresh = useCallback(async () => {
    const list = await api.get('/stations')
    setStations(list)
    const id = selected ?? list[0]?.id
    if (id) {
      setSelected(id)
      setState(await api.get(`/cabinet/${id}/state`))
    }
  }, [selected])

  useEffect(() => {
    refresh()
    const t = setInterval(refresh, 3000)
    return () => clearInterval(t)
  }, [refresh])

  const say = (msg) => setLog((l) => [`${new Date().toLocaleTimeString()}  ${msg}`, ...l].slice(0, 30))

  const act = async (fn, label) => {
    try {
      const r = await fn()
      say(`${label}：${r.delivery === 'queued' ? '柜机离线，事件已存入本地队列' : `事件已上报（${r.result}）`} [${r.event_uid?.slice(0, 18)}…]`)
      await refresh()
    } catch (e) {
      say(`${label} 失败：${e.message}`)
    }
  }

  const toggle = async () => {
    const online = !state.station.cabinet_online
    await api.post(`/cabinet/${selected}/online`, { online })
    say(online ? '柜机恢复在线' : '柜机已离线（事件将暂存本地）')
    await refresh()
  }

  const sync = async () => {
    const r = await api.post(`/cabinet/${selected}/sync`)
    const ok = r.results.filter((x) => !x.duplicate).length
    const dup = r.results.filter((x) => x.duplicate).length
    say(`补传完成：${ok} 条新事件已处理，${dup} 条重复事件被忽略`)
    await refresh()
  }

  return (
    <div>
      <div className="card">
        <label>选择柜机：</label>
        <select value={selected ?? ''} onChange={(e) => setSelected(Number(e.target.value))}>
          {stations.map((s) => (
            <option key={s.id} value={s.id}>{s.name}</option>
          ))}
        </select>
        {state && (
          <>
            <span className={state.station.cabinet_online ? 'badge online' : 'badge offline'}>
              {state.station.cabinet_online ? '在线' : '离线'}
            </span>
            <button className="link" onClick={toggle}>
              {state.station.cabinet_online ? '模拟断网' : '恢复在线'}
            </button>
            {state.queued_events > 0 && (
              <button className="link" onClick={sync}>
                补传 {state.queued_events} 条积压事件
              </button>
            )}
          </>
        )}
      </div>

      {state && (
        <>
          <h2>待处理柜门指令</h2>
          {state.pending_commands.length === 0 && <p className="muted">暂无打开的柜门</p>}
          {state.pending_commands.map((c) => (
            <div key={c.id} className="card">
              <strong>柜位 {c.slot_label}</strong>
              <span className="badge">{c.purpose === 'borrow' ? '借伞开门' : '还伞开门'}</span>
              <span className="muted">订单 {c.order_no}</span>
              {c.purpose === 'borrow' ? (
                <button onClick={() => act(() => api.post(`/cabinet/${selected}/take`, { slot_id: c.slot_id }), '模拟用户取伞')}>
                  模拟用户取走雨伞
                </button>
              ) : (
                <button onClick={() => act(() => api.post(`/cabinet/${selected}/place`, { slot_id: c.slot_id }), '模拟放伞并关门')}>
                  模拟放伞并关门（设备确认入柜）
                </button>
              )}
            </div>
          ))}
        </>
      )}

      <h2>模拟日志</h2>
      <div className="card log">
        {log.length === 0 && <span className="muted">暂无记录</span>}
        {log.map((line, i) => <div key={i}>{line}</div>)}
      </div>
    </div>
  )
}
