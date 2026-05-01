import React from 'react'
import { useForm } from '@inertiajs/react'
import TenantLayout from '../../layouts/TenantLayout'
import {
  Bot,
  Clock,
  Bell,
  FileText,
  Moon,
  Save,
  CheckCircle2,
  AlertCircle,
} from 'lucide-react'

function SectionCard({ title, icon: Icon, children }) {
  return (
    <div className="rounded-xl border border-slate-200 bg-white p-5">
      <div className="mb-4 flex items-center gap-2 border-b border-slate-100 pb-3">
        {Icon && <Icon className="h-4 w-4 text-emerald-600" />}
        <h2 className="text-sm font-semibold text-slate-700">{title}</h2>
      </div>
      {children}
    </div>
  )
}

function FieldRow({ label, hint, children }) {
  return (
    <div className="grid grid-cols-1 gap-1.5 py-3 sm:grid-cols-3 sm:gap-4">
      <div className="sm:col-span-1">
        <label className="text-sm font-medium text-slate-700">{label}</label>
        {hint && <p className="mt-0.5 text-xs text-slate-400">{hint}</p>}
      </div>
      <div className="sm:col-span-2">{children}</div>
    </div>
  )
}

function Select({ value, onChange, options, error }) {
  return (
    <div>
      <select
        value={value}
        onChange={onChange}
        className={`w-full rounded-lg border px-3 py-2 text-sm text-slate-700 focus:outline-none focus:ring-2 focus:ring-emerald-500 ${
          error ? 'border-red-400 bg-red-50' : 'border-slate-200 bg-white'
        }`}
      >
        {options.map((opt) => (
          <option key={opt.value} value={opt.value}>
            {opt.label}
          </option>
        ))}
      </select>
      {error && <p className="mt-1 text-xs text-red-500">{error}</p>}
    </div>
  )
}

function NumberInput({ value, onChange, min, max, unit, error }) {
  return (
    <div>
      <div className="flex items-center gap-2">
        <input
          type="number"
          value={value}
          onChange={onChange}
          min={min}
          max={max}
          className={`w-32 rounded-lg border px-3 py-2 text-sm text-slate-700 focus:outline-none focus:ring-2 focus:ring-emerald-500 ${
            error ? 'border-red-400 bg-red-50' : 'border-slate-200 bg-white'
          }`}
        />
        {unit && <span className="text-sm text-slate-500">{unit}</span>}
      </div>
      {error && <p className="mt-1 text-xs text-red-500">{error}</p>}
    </div>
  )
}

function Toggle({ checked, onChange, label }) {
  return (
    <label className="flex cursor-pointer items-center gap-3">
      <button
        type="button"
        role="switch"
        aria-checked={checked}
        onClick={() => onChange(!checked)}
        className={`relative inline-flex h-6 w-11 items-center rounded-full transition-colors focus:outline-none focus:ring-2 focus:ring-emerald-500 ${
          checked ? 'bg-emerald-500' : 'bg-slate-200'
        }`}
      >
        <span
          className={`inline-block h-4 w-4 transform rounded-full bg-white shadow transition-transform ${
            checked ? 'translate-x-6' : 'translate-x-1'
          }`}
        />
      </button>
      {label && <span className="text-sm text-slate-600">{label}</span>}
    </label>
  )
}

export default function AiConfig({ settings }) {
  const s = settings ?? {}

  const { data, setData, post, processing, errors, recentlySuccessful } = useForm({
    ai_tone:                  s.ai_tone ?? 'professional',
    reply_delay_seconds:      s.reply_delay_seconds ?? 3,
    followup_enabled:         s.followup_enabled ?? false,
    followup_delay_hours:     s.followup_delay_hours ?? 24,
    pricelist_mode:           s.pricelist_mode ?? 'text_first',
    pricelist_min_requirement: s.pricelist_min_requirement ?? 'name_only',
    pricelist_file_enabled:   s.pricelist_file_enabled ?? false,
    out_of_hours_auto_reply:  s.out_of_hours_auto_reply ?? false,
    out_of_hours_message:     s.out_of_hours_message ?? '',
  })

  function submit(e) {
    e.preventDefault()
    post('/tenant/ai-config')
  }

  return (
    <TenantLayout title="Konfigurasi AI">
      <form onSubmit={submit} className="space-y-4">
        {/* Tone & Timing */}
        <SectionCard title="Perilaku & Nada Bicara AI" icon={Bot}>
          <div className="divide-y divide-slate-100">
            <FieldRow
              label="Nada Bicara AI"
              hint="Menentukan gaya bahasa yang digunakan AI saat membalas pelanggan."
            >
              <Select
                value={data.ai_tone}
                onChange={(e) => setData('ai_tone', e.target.value)}
                error={errors.ai_tone}
                options={[
                  { value: 'professional', label: 'Profesional' },
                  { value: 'casual',       label: 'Santai (Casual)' },
                  { value: 'friendly',     label: 'Ramah (Friendly)' },
                  { value: 'formal',       label: 'Formal' },
                ]}
              />
            </FieldRow>

            <FieldRow
              label="Jeda Balas"
              hint="Simulasi jeda manusia sebelum AI mengirim balasan (dalam detik)."
            >
              <NumberInput
                value={data.reply_delay_seconds}
                onChange={(e) => setData('reply_delay_seconds', parseInt(e.target.value, 10))}
                min={0}
                max={30}
                unit="detik"
                error={errors.reply_delay_seconds}
              />
            </FieldRow>
          </div>
        </SectionCard>

        {/* Follow-up */}
        <SectionCard title="Follow-up Otomatis" icon={Bell}>
          <div className="divide-y divide-slate-100">
            <FieldRow
              label="Aktifkan Follow-up"
              hint="AI akan mengirim pesan lanjutan jika lead tidak membalas setelah waktu tertentu."
            >
              <Toggle
                checked={data.followup_enabled}
                onChange={(val) => setData('followup_enabled', val)}
                label={data.followup_enabled ? 'Aktif' : 'Nonaktif'}
              />
            </FieldRow>

            {data.followup_enabled && (
              <FieldRow
                label="Jeda Follow-up"
                hint="Berapa jam setelah tidak ada balasan, AI mengirim pesan follow-up."
              >
                <NumberInput
                  value={data.followup_delay_hours}
                  onChange={(e) => setData('followup_delay_hours', parseInt(e.target.value, 10))}
                  min={1}
                  max={168}
                  unit="jam"
                  error={errors.followup_delay_hours}
                />
              </FieldRow>
            )}
          </div>
        </SectionCard>

        {/* Pricelist */}
        <SectionCard title="Kebijakan Pricelist" icon={FileText}>
          <div className="divide-y divide-slate-100">
            <FieldRow
              label="Mode Pricelist"
              hint="Cara AI menyampaikan informasi harga kepada pelanggan."
            >
              <Select
                value={data.pricelist_mode}
                onChange={(e) => setData('pricelist_mode', e.target.value)}
                error={errors.pricelist_mode}
                options={[
                  { value: 'text_first', label: 'Teks Dulu (kirim file jika diminta)' },
                  { value: 'file_first', label: 'File Dulu (langsung lampirkan PDF/gambar)' },
                ]}
              />
            </FieldRow>

            <FieldRow
              label="Syarat Minimum Pricelist"
              hint="Data minimum yang harus diberikan pelanggan sebelum AI mengirim pricelist."
            >
              <Select
                value={data.pricelist_min_requirement}
                onChange={(e) => setData('pricelist_min_requirement', e.target.value)}
                error={errors.pricelist_min_requirement}
                options={[
                  { value: 'name_only',      label: 'Nama Saja' },
                  { value: 'name_date',      label: 'Nama + Tanggal Acara' },
                ]}
              />
            </FieldRow>

            <FieldRow
              label="Lampiran File Pricelist"
              hint="Izinkan AI mengirim file pricelist (PDF/gambar) yang diunggah di Data Bisnis."
            >
              <Toggle
                checked={data.pricelist_file_enabled}
                onChange={(val) => setData('pricelist_file_enabled', val)}
                label={data.pricelist_file_enabled ? 'Diizinkan' : 'Hanya teks'}
              />
            </FieldRow>
          </div>
        </SectionCard>

        {/* Out of hours */}
        <SectionCard title="Balasan Di Luar Jam Operasional" icon={Moon}>
          <div className="divide-y divide-slate-100">
            <FieldRow
              label="Auto-reply Di Luar Jam"
              hint="AI otomatis membalas dengan pesan khusus ketika ada pesan di luar jam kerja."
            >
              <Toggle
                checked={data.out_of_hours_auto_reply}
                onChange={(val) => setData('out_of_hours_auto_reply', val)}
                label={data.out_of_hours_auto_reply ? 'Aktif' : 'Nonaktif'}
              />
            </FieldRow>

            {data.out_of_hours_auto_reply && (
              <FieldRow
                label="Pesan Di Luar Jam"
                hint="Pesan yang dikirim AI saat menerima pesan di luar jam operasional."
              >
                <div>
                  <textarea
                    value={data.out_of_hours_message}
                    onChange={(e) => setData('out_of_hours_message', e.target.value)}
                    rows={3}
                    placeholder="Contoh: Terima kasih sudah menghubungi kami. Kami sedang offline. Tim kami akan membalas pada jam kerja (09.00–17.00 WIB)."
                    className={`w-full rounded-lg border px-3 py-2 text-sm text-slate-700 placeholder:text-slate-300 focus:outline-none focus:ring-2 focus:ring-emerald-500 ${
                      errors.out_of_hours_message ? 'border-red-400 bg-red-50' : 'border-slate-200 bg-white'
                    }`}
                  />
                  {errors.out_of_hours_message && (
                    <p className="mt-1 text-xs text-red-500">{errors.out_of_hours_message}</p>
                  )}
                </div>
              </FieldRow>
            )}
          </div>
        </SectionCard>

        {/* Save bar */}
        <div className="flex items-center justify-between rounded-xl border border-slate-200 bg-white px-5 py-3">
          <div className="flex items-center gap-2">
            {recentlySuccessful ? (
              <>
                <CheckCircle2 className="h-4 w-4 text-emerald-500" />
                <span className="text-sm text-emerald-600">Tersimpan.</span>
              </>
            ) : Object.keys(errors).length > 0 ? (
              <>
                <AlertCircle className="h-4 w-4 text-red-500" />
                <span className="text-sm text-red-500">Ada kesalahan input.</span>
              </>
            ) : (
              <span className="text-xs text-slate-400">Perubahan belum disimpan.</span>
            )}
          </div>
          <button
            type="submit"
            disabled={processing}
            className="flex items-center gap-2 rounded-lg bg-emerald-600 px-4 py-2 text-sm font-semibold text-white transition hover:bg-emerald-700 disabled:opacity-50"
          >
            <Save className="h-4 w-4" />
            {processing ? 'Menyimpan…' : 'Simpan Konfigurasi'}
          </button>
        </div>
      </form>
    </TenantLayout>
  )
}
