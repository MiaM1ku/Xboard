import { useState, type FormEvent } from 'react'
import { Database, ShieldCheck } from 'lucide-react'
import { api } from '../lib/api'
import type { RuleSetTemplate } from '../types'
import { Badge, Button, Field, Input, Modal, Select, Switch, useToast } from './ui'

export function RuleSetModal({ ruleSet, onClose, onSaved }: {
  ruleSet?: RuleSetTemplate
  onClose: () => void
  onSaved: () => void
}) {
  const [name, setName] = useState(ruleSet?.name || '')
  const [tag, setTag] = useState(ruleSet?.tag || '')
  const [enabled, setEnabled] = useState(ruleSet ? ruleSet.enabled : true)
  const [singboxType, setSingboxType] = useState<'remote' | 'local'>(ruleSet?.settings.singbox.type || 'remote')
  const [singboxFormat, setSingboxFormat] = useState<'binary' | 'source'>(ruleSet?.settings.singbox.format || 'binary')
  const [singboxURL, setSingboxURL] = useState(ruleSet?.settings.singbox.url || '')
  const [singboxPath, setSingboxPath] = useState(ruleSet?.settings.singbox.path || '')
  const [updateInterval, setUpdateInterval] = useState(ruleSet?.settings.singbox.update_interval || '1d')
  const [downloadDetour, setDownloadDetour] = useState(ruleSet?.settings.singbox.download_detour || '')
  const [xrayType, setXrayType] = useState<RuleSetTemplate['settings']['xray']['type']>(ruleSet?.settings.xray.type || 'geosite')
  const [xrayTag, setXrayTag] = useState(ruleSet?.settings.xray.tag || '')
  const [xrayURL, setXrayURL] = useState(ruleSet?.settings.xray.url || '')
  const [xrayFileName, setXrayFileName] = useState(ruleSet?.settings.xray.file_name || '')
  const [saving, setSaving] = useState(false)
  const [error, setError] = useState('')
  const { notify } = useToast()
  const xrayExternal = xrayType === 'ext-domain' || xrayType === 'ext-ip'

  async function submit(event: FormEvent) {
    event.preventDefault()
    setSaving(true)
    setError('')
    try {
      await api.adminPost('server/routing-template/rule-set/save', {
        id: ruleSet?.id,
        name,
        tag,
        enabled,
        settings: {
          singbox: {
            type: singboxType,
            format: singboxFormat,
            url: singboxType === 'remote' ? singboxURL : null,
            path: singboxType === 'local' ? singboxPath : null,
            update_interval: singboxType === 'remote' ? updateInterval || null : null,
            download_detour: singboxType === 'remote' ? downloadDetour || null : null,
          },
          xray: {
            type: xrayType,
            tag: xrayTag,
            url: xrayExternal ? xrayURL || null : null,
            file_name: xrayExternal ? xrayFileName : null,
          },
        },
      })
      notify(ruleSet ? '规则集已更新并准备重新下发' : '规则集已创建')
      onSaved()
      onClose()
    } catch (reason) {
      setError(reason instanceof Error ? reason.message : '保存规则集失败')
    } finally {
      setSaving(false)
    }
  }

  return (
    <Modal open onClose={onClose} width="large" title={ruleSet ? `编辑 ${ruleSet.name}` : '新建规则集'} description="一个逻辑标签同时配置 sing-box 与 Xray 的原生来源；节点只使用当前核心对应的一侧。" footer={<><Button variant="secondary" onClick={onClose}>取消</Button><Button loading={saving} onClick={() => document.getElementById('rule-set-submit')?.click()}>保存规则集</Button></>}>
      <form className="form-section" onSubmit={submit}>
        {error ? <div className="login-error" role="alert">{error}</div> : null}
        <div className="form-grid">
          <Field label="显示名称" required><Input value={name} onChange={(event) => setName(event.target.value)} placeholder="例如 AI 非中国区" required /></Field>
          <Field label="逻辑标签" hint="路由规则引用的稳定标识，只允许字母、数字、点、下划线和短横线。" required><Input className="mono" value={tag} onChange={(event) => setTag(event.target.value)} placeholder="ai-non-cn" pattern="[A-Za-z0-9_.-]+" required /></Field>
        </div>

        <section className="ruleset-source-card" aria-labelledby="singbox-source-title">
          <header><div><Database size={18} /><div><h3 id="singbox-source-title">sing-box 来源</h3><p>生成 `route.rule_set` 并由核心缓存更新。</p></div></div><Badge tone="info">SRS</Badge></header>
          <div className="form-grid">
            <Field label="来源类型"><Select value={singboxType} onChange={(event) => setSingboxType(event.target.value as typeof singboxType)}><option value="remote">远程 URL</option><option value="local">本地文件</option></Select></Field>
            <Field label="格式"><Select value={singboxFormat} onChange={(event) => setSingboxFormat(event.target.value as typeof singboxFormat)}><option value="binary">binary (.srs)</option><option value="source">source (.json)</option></Select></Field>
            {singboxType === 'remote' ? <>
              <Field className="span-2" label="SRS / JSON URL" required><Input type="url" value={singboxURL} onChange={(event) => setSingboxURL(event.target.value)} placeholder="https://example.com/rules/ai.srs" required /></Field>
              <Field label="更新间隔" hint="例如 12h、1d"><Input value={updateInterval} onChange={(event) => setUpdateInterval(event.target.value)} placeholder="1d" /></Field>
              <Field label="下载出口 tag" hint="留空使用默认直连下载"><Input value={downloadDetour} onChange={(event) => setDownloadDetour(event.target.value)} placeholder="direct" /></Field>
            </> : <Field className="span-2" label="节点本地路径" required><Input className="mono" value={singboxPath} onChange={(event) => setSingboxPath(event.target.value)} placeholder="/etc/xboard-node/rules/ai.srs" required /></Field>}
          </div>
        </section>

        <section className="ruleset-source-card" aria-labelledby="xray-source-title">
          <header><div><ShieldCheck size={18} /><div><h3 id="xray-source-title">Xray 映射</h3><p>Xray 不读取 `.srs`；这里映射为 geodata 或 ext 数据库选择器。</p></div></div><Badge>Xray</Badge></header>
          <div className="form-grid">
            <Field label="选择器类型"><Select value={xrayType} onChange={(event) => setXrayType(event.target.value as typeof xrayType)}><option value="geosite">geosite 域名集</option><option value="geoip">geoip 地址集</option><option value="ext-domain">ext 外部域名库</option><option value="ext-ip">ext 外部 IP 库</option></Select></Field>
            <Field label="数据标签" required><Input className="mono" value={xrayTag} onChange={(event) => setXrayTag(event.target.value)} placeholder="category-ai-!cn" required /></Field>
            {xrayExternal ? <>
              <Field label="保存文件名" required><Input className="mono" value={xrayFileName} onChange={(event) => setXrayFileName(event.target.value)} placeholder="custom-geosite.dat" pattern="[A-Za-z0-9_.-]+" required /></Field>
              <Field label="远程下载 URL" hint="留空表示文件已存在于节点 geo_data_dir"><Input type="url" value={xrayURL} onChange={(event) => setXrayURL(event.target.value)} placeholder="https://example.com/custom-geosite.dat" /></Field>
            </> : null}
          </div>
        </section>

        <Switch checked={enabled} onChange={setEnabled} label="启用规则集" />
        <button id="rule-set-submit" type="submit" hidden />
      </form>
    </Modal>
  )
}
