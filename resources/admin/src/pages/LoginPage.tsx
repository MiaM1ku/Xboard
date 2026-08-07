import { useState, type FormEvent } from 'react'
import { Boxes, KeyRound, Network, Route, ServerCog, UsersRound, AlertCircle } from 'lucide-react'
import { api } from '../lib/api'
import { Button, Field, Input } from '../components/ui'

export function LoginPage({ onLogin }: { onLogin: () => Promise<void> | void }) {
  const [email, setEmail] = useState('')
  const [password, setPassword] = useState('')
  const [loading, setLoading] = useState(false)
  const [error, setError] = useState('')
  const title = window.settings?.title || 'Xboard'

  async function submit(event: FormEvent) {
    event.preventDefault()
    setError('')
    setLoading(true)
    try {
      await api.login(email, password)
      await onLogin()
    } catch (reason) {
      setError(reason instanceof Error ? reason.message : '登录失败，请检查账号与密码')
    } finally {
      setLoading(false)
    }
  }

  return (
    <div className="login-page">
      <main className="login-panel">
        <div className="login-form-wrap">
          <div className="login-brand">
            {window.settings?.logo ? <img src={window.settings.logo} alt="" /> : <Boxes aria-hidden="true" />}
            <div><strong>{title}</strong><span>Self-hosted Console</span></div>
          </div>
          <h1>登录管理后台</h1>
          <p>使用管理员账号继续。登录状态通过安全 Cookie 保存，不在浏览器存储访问令牌。</p>
          <form className="login-form" onSubmit={submit}>
            {error ? <div className="login-error" role="alert"><AlertCircle size={16} />{error}</div> : null}
            <Field label="管理员邮箱" required>
              <Input type="email" autoComplete="username" value={email} onChange={(event) => setEmail(event.target.value)} placeholder="admin@example.com" required autoFocus />
            </Field>
            <Field label="密码" required>
              <Input type="password" autoComplete="current-password" value={password} onChange={(event) => setPassword(event.target.value)} placeholder="输入登录密码" required />
            </Field>
            <Button type="submit" loading={loading}>登录</Button>
          </form>
          <div className="login-footer"><a href="/">返回用户前台</a><span>Xboard {window.settings?.version || ''}</span></div>
        </div>
      </main>
      <aside className="login-aside" aria-label="管理端功能介绍">
        <div className="login-aside-top"><KeyRound size={15} /> Private distribution</div>
        <div className="login-aside-content">
          <h2>一个节点，多台后端。<br />清晰管理每条出站路径。</h2>
          <p>面向自用分发场景保留核心运维能力：多机器 DNS 均衡、双核心配置、自定义 outbound / route，以及不会互相覆盖的在线设备来源状态。</p>
          <div className="feature-list">
            <div className="feature-item"><Network size={16} />原 node_id 接入</div>
            <div className="feature-item"><ServerCog size={16} />xbnode 机器接入</div>
            <div className="feature-item"><Route size={16} />出站与路由模板</div>
            <div className="feature-item"><UsersRound size={16} />直接用户授权</div>
          </div>
        </div>
        <div className="login-aside-bottom">支付与商业订阅模块已从此构建中移除。</div>
      </aside>
    </div>
  )
}
