import { NavLink, Route, Routes } from 'react-router-dom'
import StationsPage from './pages/StationsPage.jsx'
import BorrowPage from './pages/BorrowPage.jsx'
import ReturnPage from './pages/ReturnPage.jsx'
import AdminPage from './pages/AdminPage.jsx'

export default function App() {
  return (
    <div className="app">
      <header className="topbar">
        <div className="brand">☂️ 社区共享雨伞</div>
        <nav>
          <NavLink to="/" end>站点</NavLink>
          <NavLink to="/borrow">借伞</NavLink>
          <NavLink to="/return">还伞</NavLink>
          <NavLink to="/admin">管理</NavLink>
        </nav>
      </header>
      <main>
        <Routes>
          <Route path="/" element={<StationsPage />} />
          <Route path="/borrow" element={<BorrowPage />} />
          <Route path="/return" element={<ReturnPage />} />
          <Route path="/admin" element={<AdminPage />} />
        </Routes>
      </main>
    </div>
  )
}
