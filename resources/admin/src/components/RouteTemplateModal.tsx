import { useState, type FormEvent } from 'react'
import { Plus, Trash2 } from 'lucide-react'
import { api } from '../lib/api'
import type { RouteRule, RouteTemplate, RuleSetTemplate } from '../types'
import { Button, Field, Input, Modal, Select, Switch, Textarea, useToast } from './ui'

function blankRule(index: number): RouteRule {
  return { name: `分流规则 ${index + 1}`, match: {}, action: { type: 'direct' } }
}

function listText(values?: Array<string | number>) {
  return (values || []).join('\n')
}

function parseList(value: string) {
  return value.split(/[\n,]+/).map((item) => item.trim()).filter(Boolean)
}

function updateMatch(rule: RouteRule, key: keyof NonNullable<RouteRule['match']>, value: string[]): RouteRule {
  const match = { ...(rule.match || {}), [key]: value }
  if (!value.length) delete match[key]
  return { ...rule, match }
}

function RouteRuleEditor({ rule, index, ruleSets, onChange, onRemove }: {
  rule: RouteRule
  index: number
  ruleSets: RuleSetTemplate[]
  onChange: (rule: RouteRule) => void
  onRemove: () => void
}) {
  const selectedSets = rule.match?.rule_sets || []
  return (
    <article className="route-rule-card">
      <header><div><span className="rule-index">{index + 1}</span><Input value={rule.name || ''} onChange={(event) => onChange({ ...rule, name: event.target.value })} aria-label={`规则 ${index + 1} 名称`} placeholder="规则名称" /></div><Button variant="ghost" size="icon" onClick={onRemove} aria-label={`删除规则 ${index + 1}`}><Trash2 size={15} /></Button></header>
      <div className="rule-editor-grid">
        <section className="rule-match-panel">
          <h4>匹配条件</h4>
          {ruleSets.length ? <div className="ruleset-picker" role="group" aria-label="选择规则集">{ruleSets.map((item) => <label key={item.uuid}><input type="checkbox" checked={selectedSets.includes(item.uuid)} onChange={(event) => onChange(updateMatch(rule, 'rule_sets', event.target.checked ? [...selectedSets, item.uuid] : selectedSets.filter((uuid) => uuid !== item.uuid)))} /><span><strong>{item.name}</strong><small>{item.tag}</small></span></label>)}</div> : <p className="field-hint">尚未创建规则集；仍可直接填写域名或 CIDR。</p>}
          <div className="form-grid compact-grid">
            <Field label="域名后缀" hint="每行一个，例如 example.com"><Textarea value={listText(rule.match?.domain_suffixes)} onChange={(event) => onChange(updateMatch(rule, 'domain_suffixes', parseList(event.target.value)))} rows={3} /></Field>
            <Field label="精确域名" hint="每行一个完整域名"><Textarea value={listText(rule.match?.domains)} onChange={(event) => onChange(updateMatch(rule, 'domains', parseList(event.target.value)))} rows={3} /></Field>
            <Field label="目标 CIDR"><Textarea className="mono" value={listText(rule.match?.ip_cidrs)} onChange={(event) => onChange(updateMatch(rule, 'ip_cidrs', parseList(event.target.value)))} rows={3} placeholder="1.1.1.0/24" /></Field>
            <Field label="目标端口"><Input className="mono" value={listText(rule.match?.ports)} onChange={(event) => onChange(updateMatch(rule, 'ports', parseList(event.target.value)))} placeholder="80, 443, 1000-2000" /></Field>
          </div>
          <details className="advanced-details"><summary>源地址与网络条件</summary><div className="form-grid compact-grid"><Field label="网络"><Input value={listText(rule.match?.networks)} onChange={(event) => onChange(updateMatch(rule, 'networks', parseList(event.target.value)))} placeholder="tcp, udp" /></Field><Field label="源 CIDR"><Input className="mono" value={listText(rule.match?.source_cidrs)} onChange={(event) => onChange(updateMatch(rule, 'source_cidrs', parseList(event.target.value)))} placeholder="10.0.0.0/8" /></Field><Field label="源端口"><Input className="mono" value={listText(rule.match?.source_ports)} onChange={(event) => onChange(updateMatch(rule, 'source_ports', parseList(event.target.value)))} placeholder="1024-65535" /></Field></div></details>
        </section>
        <section className="rule-action-panel">
          <h4>命中后动作</h4>
          <Field label="动作"><Select value={rule.action.type} onChange={(event) => onChange({ ...rule, action: { type: event.target.value as RouteRule['action']['type'], ...(event.target.value === 'route' ? { target: rule.action.target || '' } : {}) } })}><option value="route">指定 Outbound</option><option value="direct">直连</option><option value="block">阻断</option></Select></Field>
          {rule.action.type === 'route' ? <Field label="Outbound tag" hint="填写节点绑定出口时使用的 tag，例如 awssg。" required><Input className="mono" value={rule.action.target || ''} onChange={(event) => onChange({ ...rule, action: { ...rule.action, target: event.target.value } })} placeholder="awssg" required /></Field> : null}
          <Switch checked={!rule.disabled} onChange={(enabled) => onChange({ ...rule, disabled: !enabled })} label="启用此规则" />
        </section>
      </div>
    </article>
  )
}

export function RouteTemplateModal({ route, ruleSets, onClose, onSaved }: {
  route?: RouteTemplate
  ruleSets: RuleSetTemplate[]
  onClose: () => void
  onSaved: () => void
}) {
  const [name, setName] = useState(route?.name || '')
  const [rules, setRules] = useState<RouteRule[]>(route?.rules?.length ? route.rules : [blankRule(0)])
  const [enabled, setEnabled] = useState(route ? route.enabled : true)
  const [saving, setSaving] = useState(false)
  const [error, setError] = useState('')
  const { notify } = useToast()

  async function submit(event: FormEvent) {
    event.preventDefault()
    setError('')
    const invalidIndex = rules.findIndex((rule) => !rule.match || !Object.values(rule.match).some((items) => items?.length))
    if (invalidIndex >= 0) {
      setError(`第 ${invalidIndex + 1} 条规则至少需要一个匹配条件。`)
      return
    }
    setSaving(true)
    try {
      await api.adminPost('server/routing-template/route/save', { id: route?.id, name, rules, enabled })
      notify(route ? 'Route 模板已更新' : 'Route 模板已创建')
      onSaved()
      onClose()
    } catch (reason) {
      setError(reason instanceof Error ? reason.message : '保存路由失败')
    } finally {
      setSaving(false)
    }
  }

  return (
    <Modal open onClose={onClose} width="large" title={route ? `编辑 ${route.name}` : '新建 Route 模板'} description="规则从上到下匹配；规则集、域名、CIDR 等不同字段会编译成独立条件，避免产生无条件全局路由。" footer={<><Button variant="secondary" onClick={onClose}>取消</Button><Button loading={saving} onClick={() => document.getElementById('route-template-submit')?.click()}>保存模板</Button></>}>
      <form className="form-section" onSubmit={submit}>
        {error ? <div className="login-error" role="alert">{error}</div> : null}
        <div className="form-grid"><Field label="模板名称" required><Input value={name} onChange={(event) => setName(event.target.value)} placeholder="例如 AI 流量经新加坡" required /></Field><div className="field switch-field"><Switch checked={enabled} onChange={setEnabled} label="启用模板" /></div></div>
        <div className="route-rules-list">{rules.map((rule, index) => <RouteRuleEditor key={index} rule={rule} index={index} ruleSets={ruleSets.filter((item) => item.enabled)} onChange={(next) => setRules((items) => items.map((item, itemIndex) => itemIndex === index ? next : item))} onRemove={() => setRules((items) => items.filter((_, itemIndex) => itemIndex !== index))} />)}</div>
        <Button variant="secondary" onClick={() => setRules((items) => [...items, blankRule(items.length)])}><Plus size={15} />添加分流规则</Button>
        <button id="route-template-submit" type="submit" hidden />
      </form>
    </Modal>
  )
}
