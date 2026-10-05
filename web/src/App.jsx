import React, { useState } from 'react'
import StationsPage from './pages/StationsPage.jsx'
import CabinetPage from './pages/CabinetPage.jsx'
import AdminPage from './pages/AdminPage.jsx'

const TABS = [
  ['stations', '站点借还'],
  ['cabinet', '柜机模拟'],
  ['admin', '管理后台'],
]

export default function App() {
  const [tab, setTab] = useState('stations')
  return (
    <div className="app">
      <header>
        <h1>☂ 社区共享雨伞</h1>
        <nav>
          {TABS.map(([key, label]) => (
            <button key={key} className={tab === key ? 'tab active' : 'tab'} onClick={() => setTab(key)}>
              {label}
            </button>
          ))}
        </nav>
      </header>
      <main>
        {tab === 'stations' && <StationsPage />}
        {tab === 'cabinet' && <CabinetPage />}
        {tab === 'admin' && <AdminPage />}
      </main>
    </div>
  )
}
