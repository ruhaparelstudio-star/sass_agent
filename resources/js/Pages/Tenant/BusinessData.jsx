import React, { useEffect, useMemo } from 'react'
import { router, useForm, usePage } from '@inertiajs/react'
import TenantLayout from '../../layouts/TenantLayout'
import {
  CheckCircle2, Lock, ChevronRight, ChevronLeft, Save, Upload, Plus,
  Trash2, Info, Layers, Package, Tag, Percent, HelpCircle, FolderOpen,
  Settings2, Link2, Clock, AlertCircle, CalendarCheck, Unlink, ToggleLeft,
  ToggleRight, Mail, ExternalLink,
} from 'lucide-react'

// ─── Constants ────────────────────────────────────────────────────────────────

const STEP_ORDER = ['catalog', 'product', 'package', 'package_items', 'pricing', 'faq', 'assets', 'settings', 'calendar']

const DAY_OPTIONS = [
  { key: 'mon', label: 'Sen' },
  { key: 'tue', label: 'Sel' },
  { key: 'wed', label: 'Rab' },
  { key: 'thu', label: 'Kam' },
  { key: 'fri', label: 'Jum' },
  { key: 'sat', label: 'Sab' },
  { key: 'sun', label: 'Min' },
]

const STEP_META = {
  catalog: {
    icon: Layers,
    label: 'Katalog',
    title: 'Katalog Layanan',
    description: 'Kategori utama layanan bisnis kamu. Semua produk dan paket dikelompokkan di sini.',
    hint: 'Contoh: "Wedding Photography", "Prewedding", "Portrait Session".',
    emptyHint: 'Buat minimal 1 katalog layanan sebelum melanjutkan.',
    cta: 'Lanjut ke Produk',
    next: 'product',
  },
  product: {
    icon: Package,
    label: 'Produk',
    title: 'Produk',
    description: 'Produk spesifik dalam katalog. Misalnya dalam katalog "Wedding" ada produk "Indoor" dan "Outdoor".',
    hint: 'Pilih katalog induk, lalu isi nama produk.',
    emptyHint: 'Tambahkan minimal 1 produk sebelum membuat paket.',
    cta: 'Lanjut ke Paket',
    next: 'package',
  },
  package: {
    icon: Tag,
    label: 'Paket',
    title: 'Paket Penawaran',
    description: 'Paket adalah penawaran konkrit yang akan ditawarkan AI kepada prospek. Tiap paket bisa punya harga sendiri.',
    hint: 'Contoh: "Paket Silver", "Paket Gold", "Paket Platinum".',
    emptyHint: 'Tambahkan minimal 1 paket untuk melanjutkan ke harga.',
    cta: 'Lanjut ke Item Paket',
    next: 'package_items',
  },
  package_items: {
    icon: CheckCircle2,
    label: 'Item Paket',
    title: 'Item dalam Paket',
    description: 'Fasilitas/layanan yang termasuk dalam setiap paket. AI akan menyebut ini saat menjelaskan paket kepada calon pelanggan.',
    hint: 'Contoh: "Dokumentasi 8 jam", "2 Fotografer", "Album Premium 20 halaman".',
    emptyHint: 'Tambahkan item agar AI bisa menjelaskan isi paket secara akurat.',
    cta: 'Lanjut ke Harga',
    next: 'pricing',
  },
  pricing: {
    icon: Percent,
    label: 'Harga',
    title: 'Harga & Diskon',
    description: 'Tentukan harga resmi dan promo yang akan disampaikan AI kepada prospek.',
    hint: 'Harga dalam IDR. Diskon bisa berupa persen atau nominal tetap.',
    emptyHint: 'Isi harga minimal 1 paket agar AI tidak menebak harga.',
    cta: 'Lanjut ke FAQ',
    next: 'faq',
  },
  faq: {
    icon: HelpCircle,
    label: 'FAQ',
    title: 'FAQ (Pertanyaan Umum)',
    description: 'Jawaban standar untuk pertanyaan yang sering ditanyakan calon pelanggan.',
    hint: 'Semakin lengkap FAQ, semakin akurat AI menjawab pertanyaan spesifik.',
    emptyHint: 'Belum ada FAQ. Tambahkan untuk meningkatkan akurasi AI.',
    cta: 'Lanjut ke Aset',
    next: 'assets',
  },
  assets: {
    icon: FolderOpen,
    label: 'Aset',
    title: 'Aset & Dokumen',
    description: 'Unggah PDF daftar harga dan template invoice yang bisa dikirimkan AI ke pelanggan.',
    hint: 'Hanya file PDF yang diterima.',
    emptyHint: 'Belum ada aset terunggah.',
    cta: 'Lanjut ke Pengaturan',
    next: 'settings',
  },
  settings: {
    icon: Settings2,
    label: 'Pengaturan',
    title: 'Pengaturan Operasional',
    description: 'Atur booking link dan jam kerja agar AI tahu kapan bisnis buka dan kemana mengarahkan booking.',
    hint: 'Booking link wajib diisi agar tombol booking AI bisa berfungsi.',
    emptyHint: 'Belum ada pengaturan operasional.',
    cta: 'Lanjut ke Integrasi',
    next: 'calendar',
  },
  calendar: {
    icon: CalendarCheck,
    label: 'Kalender',
    title: 'Integrasi Google Calendar',
    description: 'Hubungkan Google Calendar agar AI bisa mengecek ketersediaan tanggal sebelum mengirim booking link.',
    hint: 'Diperlukan agar AI bisa memverifikasi tanggal acara secara otomatis. Tanpa ini, AI akan langsung melakukan handoff ke admin saat ada permintaan booking.',
    emptyHint: 'Google Calendar belum terhubung.',
    cta: null,
    next: null,
  },
}

// ─── Primitive UI ──────────────────────────────────────────────────────────────

function TextInput({ value, onChange, placeholder, type = 'text', disabled, className = '' }) {
  return (
    <input
      type={type}
      value={value ?? ''}
      onChange={onChange}
      placeholder={placeholder}
      disabled={disabled}
      className={`w-full rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm text-slate-800 placeholder:text-slate-300 focus:border-emerald-400 focus:outline-none focus:ring-2 focus:ring-emerald-100 disabled:cursor-not-allowed disabled:bg-slate-50 disabled:opacity-60 ${className}`}
    />
  )
}

function SelectInput({ value, onChange, disabled, children }) {
  return (
    <select
      value={value ?? ''}
      onChange={onChange}
      disabled={disabled}
      className="w-full rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm text-slate-800 focus:border-emerald-400 focus:outline-none focus:ring-2 focus:ring-emerald-100 disabled:cursor-not-allowed disabled:bg-slate-50 disabled:opacity-60"
    >
      {children}
    </select>
  )
}

function Textarea({ value, onChange, rows = 3, placeholder, disabled }) {
  return (
    <textarea
      value={value ?? ''}
      onChange={onChange}
      rows={rows}
      placeholder={placeholder}
      disabled={disabled}
      className="w-full rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm text-slate-800 placeholder:text-slate-300 focus:border-emerald-400 focus:outline-none focus:ring-2 focus:ring-emerald-100 disabled:cursor-not-allowed disabled:bg-slate-50 disabled:opacity-60"
    />
  )
}

function Field({ label, required, hint, error, children }) {
  return (
    <div className="space-y-1.5">
      <label className="flex items-center gap-1 text-sm font-medium text-slate-700">
        {label}
        {required && <span className="text-red-500">*</span>}
      </label>
      {children}
      {hint && !error && <p className="text-xs text-slate-400">{hint}</p>}
      {error && <p className="flex items-center gap-1 text-xs text-red-500"><AlertCircle className="h-3 w-3" />{error}</p>}
    </div>
  )
}

function SaveButton({ processing, label = 'Simpan', icon: Icon = Save, disabled }) {
  return (
    <button
      type="submit"
      disabled={processing || disabled}
      className="flex items-center gap-2 rounded-lg bg-emerald-600 px-4 py-2 text-sm font-semibold text-white transition hover:bg-emerald-700 disabled:cursor-not-allowed disabled:opacity-50"
    >
      <Icon className="h-4 w-4" />
      {processing ? 'Menyimpan…' : label}
    </button>
  )
}

function HintBox({ children }) {
  return (
    <div className="flex items-start gap-2 rounded-lg border border-blue-100 bg-blue-50 px-3 py-2.5 text-xs text-blue-700">
      <Info className="mt-0.5 h-3.5 w-3.5 shrink-0" />
      <span>{children}</span>
    </div>
  )
}

function EmptyState({ text }) {
  return <p className="py-4 text-center text-sm text-slate-400">{text}</p>
}

// ─── Stepper ───────────────────────────────────────────────────────────────────

function Stepper({ steps, onSelect }) {
  return (
    <nav aria-label="Langkah pengisian data bisnis">
      {/* Mobile: scrollable pill list */}
      <div className="flex gap-2 overflow-x-auto pb-1 md:hidden">
        {steps.map((step) => {
          const Icon = STEP_META[step.key].icon
          return (
            <button
              key={step.key}
              type="button"
              disabled={!step.unlocked}
              onClick={() => onSelect(step.key)}
              className={`flex shrink-0 items-center gap-1.5 rounded-full border px-3 py-1.5 text-xs font-medium transition ${
                step.active
                  ? 'border-emerald-400 bg-emerald-600 text-white'
                  : step.done
                  ? 'border-emerald-200 bg-emerald-50 text-emerald-700'
                  : step.unlocked
                  ? 'border-slate-200 bg-white text-slate-600 hover:border-emerald-300'
                  : 'border-slate-100 bg-slate-50 text-slate-300 cursor-not-allowed'
              }`}
            >
              {step.done && !step.active ? (
                <CheckCircle2 className="h-3.5 w-3.5" />
              ) : !step.unlocked ? (
                <Lock className="h-3.5 w-3.5" />
              ) : (
                <span className="flex h-4 w-4 items-center justify-center rounded-full bg-white/20 text-[10px] font-bold">
                  {step.index}
                </span>
              )}
              {step.label}
            </button>
          )
        })}
      </div>

      {/* Desktop: horizontal stepper with connectors */}
      <ol className="hidden items-center md:flex">
        {steps.map((step, i) => {
          const Icon = STEP_META[step.key].icon
          const isLast = i === steps.length - 1
          return (
            <React.Fragment key={step.key}>
              <li className="flex flex-col items-center">
                <button
                  type="button"
                  disabled={!step.unlocked}
                  onClick={() => onSelect(step.key)}
                  className={`flex flex-col items-center gap-1.5 group ${!step.unlocked ? 'cursor-not-allowed' : 'cursor-pointer'}`}
                >
                  <div className={`flex h-9 w-9 items-center justify-center rounded-full border-2 transition ${
                    step.active
                      ? 'border-emerald-500 bg-emerald-500 text-white shadow-md shadow-emerald-200'
                      : step.done
                      ? 'border-emerald-400 bg-emerald-50 text-emerald-600'
                      : step.unlocked
                      ? 'border-slate-300 bg-white text-slate-500 group-hover:border-emerald-300'
                      : 'border-slate-200 bg-slate-50 text-slate-300'
                  }`}>
                    {step.done && !step.active ? (
                      <CheckCircle2 className="h-4 w-4" />
                    ) : !step.unlocked ? (
                      <Lock className="h-3.5 w-3.5" />
                    ) : (
                      <span className="text-xs font-bold">{step.index}</span>
                    )}
                  </div>
                  <span className={`max-w-[72px] text-center text-[11px] font-medium leading-tight ${
                    step.active ? 'text-emerald-700' : step.done ? 'text-emerald-600' : step.unlocked ? 'text-slate-500' : 'text-slate-300'
                  }`}>
                    {step.label}
                  </span>
                </button>
              </li>
              {!isLast && (
                <div className={`mx-1 mb-6 h-0.5 flex-1 transition ${
                  steps[i + 1]?.done || steps[i + 1]?.active ? 'bg-emerald-300' : 'bg-slate-200'
                }`} />
              )}
            </React.Fragment>
          )
        })}
      </ol>
    </nav>
  )
}

// ─── Data list panel ───────────────────────────────────────────────────────────

function DataPanel({ title, count, children }) {
  return (
    <div className="flex flex-col rounded-xl border border-slate-200 bg-slate-50">
      <div className="flex items-center justify-between border-b border-slate-200 px-4 py-3">
        <h3 className="text-sm font-semibold text-slate-700">{title}</h3>
        {count > 0 && (
          <span className="rounded-full bg-emerald-100 px-2 py-0.5 text-xs font-semibold text-emerald-700">{count}</span>
        )}
      </div>
      <div className="flex-1 overflow-y-auto p-3" style={{ maxHeight: '380px' }}>
        {children}
      </div>
    </div>
  )
}

function DataRow({ primary, secondary, onDelete }) {
  return (
    <div className="flex items-start justify-between gap-2 rounded-lg border border-slate-100 bg-white px-3 py-2.5 text-sm">
      <div className="min-w-0">
        <p className="truncate font-medium text-slate-700">{primary}</p>
        {secondary && <p className="mt-0.5 truncate text-xs text-slate-400">{secondary}</p>}
      </div>
      {onDelete && (
        <button
          type="button"
          onClick={onDelete}
          className="shrink-0 rounded p-1 text-slate-300 transition hover:bg-red-50 hover:text-red-500"
          title="Hapus"
        >
          <Trash2 className="h-3.5 w-3.5" />
        </button>
      )}
    </div>
  )
}

// ─── Step forms ────────────────────────────────────────────────────────────────

function CatalogStep({ form, rows, onSubmit }) {
  return (
    <div className="grid gap-5 lg:grid-cols-2">
      <form className="space-y-4" onSubmit={onSubmit}>
        <Field label="Nama Katalog" required error={form.errors.name} hint='Contoh: "Wedding Photography"'>
          <TextInput value={form.data.name} onChange={(e) => form.setData('name', e.target.value)} placeholder="Wedding Photography" />
        </Field>
        <Field label="Deskripsi" hint="Singkat, jelaskan jenis layanan dalam katalog ini.">
          <Textarea value={form.data.description} onChange={(e) => form.setData('description', e.target.value)} rows={3} placeholder="Layanan dokumentasi pernikahan profesional…" />
        </Field>
        <SaveButton processing={form.processing} label="Tambah Katalog" icon={Plus} />
      </form>

      <DataPanel title="Katalog Tersimpan" count={rows.length}>
        {rows.length === 0
          ? <EmptyState text="Belum ada katalog. Isi form di sebelah kiri." />
          : <div className="space-y-2">{rows.map((r) => <DataRow key={r.id} primary={r.name} secondary={r.code} />)}</div>
        }
      </DataPanel>
    </div>
  )
}

function ProductStep({ form, catalogs, rows, onSubmit, disabled }) {
  return (
    <div className="grid gap-5 lg:grid-cols-2">
      <form className="space-y-4" onSubmit={onSubmit}>
        <Field label="Katalog Layanan" required error={form.errors.service_catalog_id} hint="Pilih katalog yang menjadi induk produk ini.">
          <SelectInput value={form.data.service_catalog_id} onChange={(e) => form.setData('service_catalog_id', e.target.value)} disabled={disabled || catalogs.length === 0}>
            {catalogs.length === 0
              ? <option value="">Belum ada katalog — tambahkan katalog terlebih dahulu</option>
              : <>
                  <option value="">— Pilih katalog —</option>
                  {catalogs.map((r) => <option key={r.id} value={r.id}>{r.name}</option>)}
                </>
            }
          </SelectInput>
        </Field>
        <Field label="Nama Produk" required error={form.errors.name} hint='Contoh: "Indoor Wedding", "Outdoor Wedding"'>
          <TextInput value={form.data.name} onChange={(e) => form.setData('name', e.target.value)} placeholder="Indoor Wedding" disabled={disabled} />
        </Field>
        <Field label="Deskripsi">
          <Textarea value={form.data.description} onChange={(e) => form.setData('description', e.target.value)} rows={3} disabled={disabled} placeholder="Detail singkat tentang produk ini…" />
        </Field>
        <SaveButton processing={form.processing} label="Tambah Produk" icon={Plus} disabled={disabled} />
      </form>

      <DataPanel title="Produk Tersimpan" count={rows.length}>
        {rows.length === 0
          ? <EmptyState text="Belum ada produk." />
          : <div className="space-y-2">{rows.map((r) => <DataRow key={r.id} primary={r.name} secondary={r.code} />)}</div>
        }
      </DataPanel>
    </div>
  )
}

function PackageStep({ form, products, rows, onSubmit, disabled }) {
  return (
    <div className="grid gap-5 lg:grid-cols-2">
      <form className="space-y-4" onSubmit={onSubmit}>
        <Field label="Produk" required error={form.errors.product_id} hint="Paket ini masuk ke produk mana?">
          <SelectInput value={form.data.product_id} onChange={(e) => form.setData('product_id', e.target.value)} disabled={disabled || products.length === 0}>
            {products.length === 0
              ? <option value="">Belum ada produk — tambahkan produk terlebih dahulu</option>
              : <>
                  <option value="">— Pilih produk —</option>
                  {products.map((r) => <option key={r.id} value={r.id}>{r.name}</option>)}
                </>
            }
          </SelectInput>
        </Field>
        <Field label="Nama Paket" required error={form.errors.name} hint='Contoh: "Paket Silver", "Paket Gold"'>
          <TextInput value={form.data.name} onChange={(e) => form.setData('name', e.target.value)} placeholder="Paket Silver" disabled={disabled} />
        </Field>
        <Field label="Deskripsi" hint="Gambaran singkat paket ini.">
          <Textarea value={form.data.description} onChange={(e) => form.setData('description', e.target.value)} rows={3} disabled={disabled} placeholder="Paket terjangkau untuk…" />
        </Field>
        <SaveButton processing={form.processing} label="Tambah Paket" icon={Plus} disabled={disabled} />
      </form>

      <DataPanel title="Paket Tersimpan" count={rows.length}>
        {rows.length === 0
          ? <EmptyState text="Belum ada paket." />
          : <div className="space-y-2">{rows.map((r) => <DataRow key={r.id} primary={r.name} secondary={r.code} />)}</div>
        }
      </DataPanel>
    </div>
  )
}

function PackageItemsStep({ form, packages, rows, onSubmit, disabled }) {
  const itemsByPackage = packages.map((pkg) => ({
    ...pkg,
    items: (rows ?? []).filter((item) => Number(item.package_id) === Number(pkg.id)),
  }))

  return (
    <div className="grid gap-5 lg:grid-cols-2">
      <form className="space-y-4" onSubmit={onSubmit}>
        <HintBox>
          Item paket adalah fasilitas yang termasuk dalam satu paket. AI akan menyebutnya saat menjelaskan paket kepada prospek. Contoh: "2 Fotografer", "Album Premium 20 hlm", "Drone shoot".
        </HintBox>
        <Field label="Paket" required error={form.errors.package_id} hint="Tambahkan item ke paket mana?">
          <SelectInput value={form.data.package_id} onChange={(e) => form.setData('package_id', e.target.value)} disabled={disabled || packages.length === 0}>
            {packages.length === 0
              ? <option value="">Belum ada paket — tambahkan paket terlebih dahulu</option>
              : <>
                  <option value="">— Pilih paket —</option>
                  {packages.map((r) => <option key={r.id} value={r.id}>{r.name}</option>)}
                </>
            }
          </SelectInput>
        </Field>
        <Field label="Nama Item" required error={form.errors.name}>
          <TextInput value={form.data.name} onChange={(e) => form.setData('name', e.target.value)} placeholder="Contoh: 2 Fotografer Profesional" disabled={disabled} />
        </Field>
        <Field label="Keterangan" hint="Detail tambahan, opsional.">
          <TextInput value={form.data.description} onChange={(e) => form.setData('description', e.target.value)} placeholder="Misal: termasuk editing full album" disabled={disabled} />
        </Field>
        <SaveButton processing={form.processing} label="Tambah Item" icon={Plus} disabled={disabled} />
      </form>

      <DataPanel title="Item per Paket" count={rows.length}>
        {packages.length === 0
          ? <EmptyState text="Buat paket terlebih dahulu." />
          : <div className="space-y-3">
              {itemsByPackage.map((pkg) => (
                <div key={pkg.id}>
                  <p className="mb-1.5 text-xs font-semibold uppercase tracking-wider text-slate-400">{pkg.name}</p>
                  {pkg.items.length === 0
                    ? <p className="rounded-lg border border-dashed border-slate-200 py-2 text-center text-xs text-slate-300">Belum ada item</p>
                    : <div className="space-y-1.5">
                        {pkg.items.map((item) => (
                          <DataRow
                            key={item.id}
                            primary={item.name}
                            secondary={item.description || null}
                            onDelete={() => { if (confirm('Hapus item ini?')) router.delete(`/tenant/business-data/package-items/${item.id}`, { preserveScroll: true }) }}
                          />
                        ))}
                      </div>
                  }
                </div>
              ))}
            </div>
        }
      </DataPanel>
    </div>
  )
}

function PricingStep({ priceForm, discountForm, packages, prices, discounts, discountTypes, onSubmitPrice, onSubmitDiscount, disabled }) {
  const fmtIDR = (v) => Number(v).toLocaleString('id-ID', { style: 'currency', currency: 'IDR', maximumFractionDigits: 0 })

  return (
    <div className="grid gap-6 xl:grid-cols-2">
      {/* Left: forms */}
      <div className="space-y-5">
        {/* Price form */}
        <div className="rounded-xl border border-slate-200 bg-white p-4">
          <h3 className="mb-4 flex items-center gap-2 text-sm font-semibold text-slate-700">
            <Tag className="h-4 w-4 text-emerald-500" /> Tambah Harga
          </h3>
          <form className="space-y-3" onSubmit={onSubmitPrice}>
            <Field label="Paket" required error={priceForm.errors.package_id}>
              <SelectInput value={priceForm.data.package_id} onChange={(e) => priceForm.setData('package_id', e.target.value)} disabled={disabled || packages.length === 0}>
                {packages.length === 0
                  ? <option value="">Belum ada paket — tambahkan paket terlebih dahulu</option>
                  : <>
                      <option value="">— Pilih paket —</option>
                      {packages.map((r) => <option key={r.id} value={r.id}>{r.name}</option>)}
                    </>
                }
              </SelectInput>
            </Field>
            <Field label="Label Harga" required error={priceForm.errors.label} hint='Contoh: "Harga Normal", "Early Bird"'>
              <TextInput value={priceForm.data.label} onChange={(e) => priceForm.setData('label', e.target.value)} placeholder="Harga Normal" disabled={disabled} />
            </Field>
            <div className="grid grid-cols-3 gap-3">
              <Field label="Mata Uang">
                <TextInput value={priceForm.data.currency} onChange={(e) => priceForm.setData('currency', e.target.value)} disabled={disabled} className="col-span-1" />
              </Field>
              <Field label="Nominal (IDR)" required error={priceForm.errors.amount} className="col-span-2">
                <TextInput type="number" min="0" value={priceForm.data.amount} onChange={(e) => priceForm.setData('amount', e.target.value)} placeholder="5000000" disabled={disabled} />
              </Field>
            </div>
            <SaveButton processing={priceForm.processing} label="Simpan Harga" disabled={disabled} />
          </form>
        </div>

        {/* Discount form */}
        <div className="rounded-xl border border-slate-200 bg-white p-4">
          <h3 className="mb-4 flex items-center gap-2 text-sm font-semibold text-slate-700">
            <Percent className="h-4 w-4 text-amber-500" /> Tambah Diskon
          </h3>
          <form className="space-y-3" onSubmit={onSubmitDiscount}>
            <Field label="Paket" required error={discountForm.errors.package_id}>
              <SelectInput value={discountForm.data.package_id} onChange={(e) => discountForm.setData('package_id', e.target.value)} disabled={disabled || packages.length === 0}>
                {packages.length === 0
                  ? <option value="">Belum ada paket — tambahkan paket terlebih dahulu</option>
                  : <>
                      <option value="">— Pilih paket —</option>
                      {packages.map((r) => <option key={r.id} value={r.id}>{r.name}</option>)}
                    </>
                }
              </SelectInput>
            </Field>
            <Field label="Nama Diskon" required error={discountForm.errors.name} hint='Contoh: "Promo Ramadan 20%"'>
              <TextInput value={discountForm.data.name} onChange={(e) => discountForm.setData('name', e.target.value)} placeholder="Promo Ramadan 20%" disabled={disabled} />
            </Field>
            <div className="grid grid-cols-2 gap-3">
              <Field label="Jenis Diskon">
                <SelectInput value={discountForm.data.discount_type} onChange={(e) => discountForm.setData('discount_type', e.target.value)} disabled={disabled}>
                  {discountTypes.map((t) => <option key={t} value={t}>{t === 'percentage' ? 'Persen (%)' : 'Nominal (Rp)'}</option>)}
                </SelectInput>
              </Field>
              <Field label="Nilai" required error={discountForm.errors.value}>
                <TextInput type="number" min="0" value={discountForm.data.value} onChange={(e) => discountForm.setData('value', e.target.value)} placeholder={discountForm.data.discount_type === 'percentage' ? '20' : '500000'} disabled={disabled} />
              </Field>
            </div>
            <SaveButton processing={discountForm.processing} label="Simpan Diskon" disabled={disabled} />
          </form>
        </div>
      </div>

      {/* Right: data */}
      <div className="space-y-4">
        <DataPanel title="Harga Tersimpan" count={prices.length}>
          {prices.length === 0
            ? <EmptyState text="Belum ada harga." />
            : <div className="space-y-2">
                {prices.map((r) => (
                  <DataRow
                    key={r.id}
                    primary={r.label}
                    secondary={`${r.currency} ${Number(r.amount).toLocaleString('id-ID')}`}
                  />
                ))}
              </div>
          }
        </DataPanel>

        <DataPanel title="Diskon Tersimpan" count={discounts.length}>
          {discounts.length === 0
            ? <EmptyState text="Belum ada diskon." />
            : <div className="space-y-2">
                {discounts.map((r) => (
                  <DataRow
                    key={r.id}
                    primary={r.name}
                    secondary={r.discount_type === 'percentage' ? `${r.value}%` : `Rp ${Number(r.value).toLocaleString('id-ID')}`}
                  />
                ))}
              </div>
          }
        </DataPanel>
      </div>
    </div>
  )
}

function FaqStep({ form, rows, onSubmit }) {
  return (
    <div className="grid gap-5 lg:grid-cols-2">
      <form className="space-y-4" onSubmit={onSubmit}>
        <Field label="Pertanyaan" required error={form.errors.question} hint="Tulis seperti pertanyaan nyata dari calon pelanggan.">
          <TextInput value={form.data.question} onChange={(e) => form.setData('question', e.target.value)} placeholder="Apakah tersedia paket outdoor?" />
        </Field>
        <Field label="Jawaban" required error={form.errors.answer} hint="Tulis jawaban lengkap yang akan digunakan AI.">
          <Textarea value={form.data.answer} onChange={(e) => form.setData('answer', e.target.value)} rows={5} placeholder="Ya, kami menyediakan paket outdoor dengan…" />
        </Field>
        <SaveButton processing={form.processing} label="Tambah FAQ" icon={Plus} />
      </form>

      <DataPanel title="FAQ Tersimpan" count={rows.length}>
        {rows.length === 0
          ? <EmptyState text="Belum ada FAQ." />
          : <div className="space-y-2">
              {rows.map((r) => (
                <div key={r.id} className="rounded-lg border border-slate-100 bg-white p-3 text-sm">
                  <p className="font-medium text-slate-700">Q: {r.question}</p>
                  <p className="mt-1 line-clamp-2 text-xs text-slate-400">A: {r.answer}</p>
                </div>
              ))}
            </div>
        }
      </DataPanel>
    </div>
  )
}

function AssetsStep({ pricelistForm, invoiceForm, rows, onSubmitPricelist, onSubmitInvoice }) {
  const assetTypeLabel = { pricelist: 'Daftar Harga', invoice_template: 'Template Invoice' }

  return (
    <div className="grid gap-5 lg:grid-cols-2">
      <div className="space-y-5">
        <div className="rounded-xl border border-slate-200 bg-white p-4">
          <h3 className="mb-4 flex items-center gap-2 text-sm font-semibold text-slate-700">
            <Tag className="h-4 w-4 text-emerald-500" /> PDF Daftar Harga
          </h3>
          <form className="space-y-3" onSubmit={onSubmitPricelist}>
            <Field label="Nama Tampilan" hint='Contoh: "Pricelist Mei 2025"'>
              <TextInput value={pricelistForm.data.display_name} onChange={(e) => pricelistForm.setData('display_name', e.target.value)} placeholder="Pricelist Mei 2025" />
            </Field>
            <Field label="File PDF" required error={pricelistForm.errors.file}>
              <input
                type="file"
                accept="application/pdf"
                onChange={(e) => pricelistForm.setData('file', e.target.files?.[0] ?? null)}
                className="w-full rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm text-slate-600 file:mr-3 file:rounded-md file:border-0 file:bg-emerald-50 file:px-2.5 file:py-1 file:text-xs file:font-medium file:text-emerald-700"
              />
            </Field>
            <SaveButton processing={pricelistForm.processing} label="Unggah Pricelist" icon={Upload} />
          </form>
        </div>

        <div className="rounded-xl border border-slate-200 bg-white p-4">
          <h3 className="mb-4 flex items-center gap-2 text-sm font-semibold text-slate-700">
            <FolderOpen className="h-4 w-4 text-violet-500" /> PDF Template Invoice
          </h3>
          <form className="space-y-3" onSubmit={onSubmitInvoice}>
            <Field label="Nama Tampilan" hint='Contoh: "Invoice Template v2"'>
              <TextInput value={invoiceForm.data.display_name} onChange={(e) => invoiceForm.setData('display_name', e.target.value)} placeholder="Invoice Template v2" />
            </Field>
            <Field label="File PDF" required error={invoiceForm.errors.file}>
              <input
                type="file"
                accept="application/pdf"
                onChange={(e) => invoiceForm.setData('file', e.target.files?.[0] ?? null)}
                className="w-full rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm text-slate-600 file:mr-3 file:rounded-md file:border-0 file:bg-violet-50 file:px-2.5 file:py-1 file:text-xs file:font-medium file:text-violet-700"
              />
            </Field>
            <SaveButton processing={invoiceForm.processing} label="Unggah Invoice" icon={Upload} />
          </form>
        </div>
      </div>

      <DataPanel title="Aset Terunggah" count={rows.length}>
        {rows.length === 0
          ? <EmptyState text="Belum ada aset terunggah." />
          : <div className="space-y-2">
              {rows.map((r) => (
                <DataRow
                  key={r.id}
                  primary={r.display_name || r.original_filename}
                  secondary={assetTypeLabel[r.asset_type] ?? r.asset_type}
                />
              ))}
            </div>
        }
      </DataPanel>
    </div>
  )
}

function SettingsStep({ bookingForm, businessHoursForm, bookingRows, onSubmitBooking, onSubmitBusinessHours }) {
  const activeDays = Array.isArray(businessHoursForm.data.days) ? businessHoursForm.data.days : []
  const toggleDay = (key) => {
    businessHoursForm.setData('days', activeDays.includes(key) ? activeDays.filter((v) => v !== key) : [...activeDays, key])
  }

  return (
    <div className="grid gap-5 lg:grid-cols-2">
      {/* Booking link */}
      <div className="space-y-4">
        <div className="rounded-xl border border-slate-200 bg-white p-4">
          <h3 className="mb-4 flex items-center gap-2 text-sm font-semibold text-slate-700">
            <Link2 className="h-4 w-4 text-emerald-500" /> Booking Link
          </h3>
          <form className="space-y-3" onSubmit={onSubmitBooking}>
            <Field label="URL Booking" required error={bookingForm.errors.booking_url} hint="Link yang akan dikirimkan AI saat prospek ingin booking.">
              <TextInput type="url" value={bookingForm.data.booking_url} onChange={(e) => bookingForm.setData('booking_url', e.target.value)} placeholder="https://wa.me/628xxx atau https://booking.site/form" />
            </Field>
            <div className="grid grid-cols-2 gap-3">
              <Field label="Aktif Mulai">
                <TextInput type="date" value={bookingForm.data.active_from} onChange={(e) => bookingForm.setData('active_from', e.target.value)} />
              </Field>
              <Field label="Aktif Sampai">
                <TextInput type="date" value={bookingForm.data.active_until} onChange={(e) => bookingForm.setData('active_until', e.target.value)} />
              </Field>
            </div>
            <label className="flex items-center gap-2.5 rounded-lg border border-slate-200 px-3 py-2.5 text-sm text-slate-700 hover:bg-slate-50 cursor-pointer">
              <input type="checkbox" checked={bookingForm.data.is_active === true} onChange={(e) => bookingForm.setData('is_active', e.target.checked)} className="h-4 w-4 rounded accent-emerald-600" />
              Booking link aktif (AI bisa mengirimkan link ini)
            </label>
            <SaveButton processing={bookingForm.processing} label="Simpan Booking Link" />
          </form>
        </div>

        {bookingRows.length > 0 && (
          <div className="rounded-xl border border-emerald-100 bg-emerald-50 p-4">
            <p className="mb-2 text-xs font-semibold uppercase tracking-wider text-emerald-600">Link Aktif Sekarang</p>
            {bookingRows.filter((r) => r.is_active).map((r) => (
              <a key={r.id} href={r.booking_url} target="_blank" rel="noreferrer" className="flex items-center gap-1.5 truncate text-sm text-emerald-700 hover:underline">
                <Link2 className="h-3.5 w-3.5 shrink-0" /> {r.booking_url}
              </a>
            ))}
          </div>
        )}
      </div>

      {/* Business hours */}
      <div className="rounded-xl border border-slate-200 bg-white p-4">
        <h3 className="mb-4 flex items-center gap-2 text-sm font-semibold text-slate-700">
          <Clock className="h-4 w-4 text-blue-500" /> Jam Operasional
        </h3>
        <form className="space-y-4" onSubmit={onSubmitBusinessHours}>
          <label className="flex items-center gap-2.5 rounded-lg border border-slate-200 px-3 py-2.5 text-sm text-slate-700 hover:bg-slate-50 cursor-pointer">
            <input type="checkbox" checked={businessHoursForm.data.enabled === true} onChange={(e) => businessHoursForm.setData('enabled', e.target.checked)} className="h-4 w-4 rounded accent-emerald-600" />
            Aktifkan jam operasional (AI tahu kapan bisnis buka)
          </label>

          <Field label="Timezone">
            <TextInput value={businessHoursForm.data.timezone} onChange={(e) => businessHoursForm.setData('timezone', e.target.value)} placeholder="Asia/Jakarta" />
          </Field>

          <div className="grid grid-cols-2 gap-3">
            <Field label="Jam Buka">
              <TextInput type="time" value={businessHoursForm.data.start_time} onChange={(e) => businessHoursForm.setData('start_time', e.target.value)} />
            </Field>
            <Field label="Jam Tutup">
              <TextInput type="time" value={businessHoursForm.data.end_time} onChange={(e) => businessHoursForm.setData('end_time', e.target.value)} />
            </Field>
          </div>

          <Field label="Hari Operasional">
            <div className="flex flex-wrap gap-2">
              {DAY_OPTIONS.map((day) => {
                const isActive = activeDays.includes(day.key)
                return (
                  <button
                    key={day.key}
                    type="button"
                    onClick={() => toggleDay(day.key)}
                    className={`rounded-lg border px-3 py-1.5 text-xs font-semibold transition ${
                      isActive
                        ? 'border-emerald-400 bg-emerald-500 text-white'
                        : 'border-slate-200 bg-white text-slate-500 hover:border-emerald-300'
                    }`}
                  >
                    {day.label}
                  </button>
                )
              })}
            </div>
          </Field>

          <SaveButton processing={businessHoursForm.processing} label="Simpan Jam Operasional" />
        </form>
      </div>
    </div>
  )
}

function CalendarStep({ calendarConnection, calendarSettings }) {
  const isConnected    = calendarConnection?.status === 'connected'
  const isEnabled      = calendarConnection?.is_enabled === true
  const email          = calendarConnection?.email ?? null
  const calendarId     = calendarConnection?.calendar_id ?? 'primary'
  const currentMaxEvents = calendarSettings?.max_events_per_date ?? 1
  const csrf           = document.querySelector('meta[name=csrf-token]')?.content ?? ''

  return (
    <div className="space-y-5">
      {/* Section 1 — Status Koneksi */}
      <div className="grid gap-5 lg:grid-cols-2">
        <div className="rounded-xl border border-slate-200 bg-white p-5">
          <h3 className="mb-4 flex items-center gap-2 text-sm font-semibold text-slate-700">
            <CalendarCheck className="h-4 w-4 text-emerald-500" /> Status Koneksi
          </h3>

          <div className={`mb-4 flex items-center gap-3 rounded-xl border px-4 py-3 ${
            isConnected ? 'border-emerald-200 bg-emerald-50' : 'border-slate-200 bg-slate-50'
          }`}>
            <div className={`h-2.5 w-2.5 shrink-0 rounded-full ${isConnected ? 'bg-emerald-500' : 'bg-slate-300'}`} />
            <div className="flex-1 min-w-0">
              <p className={`text-sm font-semibold ${isConnected ? 'text-emerald-700' : 'text-slate-500'}`}>
                {isConnected ? 'Terhubung' : 'Belum Terhubung'}
              </p>
              {isConnected && email && (
                <p className="flex items-center gap-1 truncate text-xs text-emerald-600">
                  <Mail className="h-3 w-3 shrink-0" /> {email}
                </p>
              )}
            </div>
            {isConnected && (
              <span className={`shrink-0 rounded-full px-2 py-0.5 text-xs font-semibold ${
                isEnabled ? 'bg-emerald-100 text-emerald-700' : 'bg-slate-100 text-slate-500'
              }`}>
                {isEnabled ? 'Aktif' : 'Nonaktif'}
              </span>
            )}
          </div>

          {isConnected && (
            <div className="mb-4 rounded-lg border border-slate-100 bg-slate-50 px-3 py-2.5 text-xs text-slate-600">
              <p className="font-medium text-slate-500 mb-0.5">Akun Google</p>
              <p className="font-mono">{email ?? '—'}</p>
              <p className="font-medium text-slate-500 mt-1.5 mb-0.5">Calendar ID</p>
              <p className="font-mono">{calendarId}</p>
            </div>
          )}

          <div className="flex flex-col gap-2">
            {!isConnected ? (
              <a
                href="/tenant/calendar/connect"
                className="flex items-center justify-center gap-2 rounded-lg bg-emerald-600 px-4 py-2.5 text-sm font-semibold text-white transition hover:bg-emerald-700"
              >
                <CalendarCheck className="h-4 w-4" />
                Hubungkan Google Calendar
                <ExternalLink className="h-3.5 w-3.5 opacity-70" />
              </a>
            ) : (
              <>
                <form action="/tenant/calendar/toggle" method="POST" className="contents">
                  <input type="hidden" name="_token" value={csrf} />
                  <button
                    type="submit"
                    className={`flex items-center justify-center gap-2 rounded-lg border px-4 py-2.5 text-sm font-semibold transition ${
                      isEnabled
                        ? 'border-amber-200 bg-amber-50 text-amber-700 hover:bg-amber-100'
                        : 'border-emerald-200 bg-emerald-50 text-emerald-700 hover:bg-emerald-100'
                    }`}
                  >
                    {isEnabled ? <ToggleLeft className="h-4 w-4" /> : <ToggleRight className="h-4 w-4" />}
                    {isEnabled ? 'Nonaktifkan Sementara' : 'Aktifkan Kembali'}
                  </button>
                </form>
                <form action="/tenant/calendar/disconnect" method="POST" className="contents">
                  <input type="hidden" name="_token" value={csrf} />
                  <button
                    type="submit"
                    onClick={(e) => { if (!confirm('Putuskan koneksi Google Calendar? Token akses akan dihapus.')) e.preventDefault() }}
                    className="flex items-center justify-center gap-2 rounded-lg border border-red-200 bg-red-50 px-4 py-2.5 text-sm font-semibold text-red-600 transition hover:bg-red-100"
                  >
                    <Unlink className="h-4 w-4" />
                    Putuskan Koneksi
                  </button>
                </form>
              </>
            )}
          </div>
        </div>

        {/* Info Panel */}
        <div className="space-y-4">
          <div className="rounded-xl border border-blue-100 bg-blue-50 p-4">
            <h4 className="mb-2 flex items-center gap-2 text-sm font-semibold text-blue-700">
              <Info className="h-4 w-4" /> Cara Kerja Integrasi
            </h4>
            <ul className="space-y-2 text-xs text-blue-600">
              <li className="flex items-start gap-2">
                <span className="mt-0.5 flex h-4 w-4 shrink-0 items-center justify-center rounded-full bg-blue-200 text-[9px] font-bold text-blue-700">1</span>
                AI mendeteksi tanggal dari percakapan pelanggan
              </li>
              <li className="flex items-start gap-2">
                <span className="mt-0.5 flex h-4 w-4 shrink-0 items-center justify-center rounded-full bg-blue-200 text-[9px] font-bold text-blue-700">2</span>
                Sistem menghitung jumlah event di Google Calendar pada tanggal tersebut
              </li>
              <li className="flex items-start gap-2">
                <span className="mt-0.5 flex h-4 w-4 shrink-0 items-center justify-center rounded-full bg-blue-200 text-[9px] font-bold text-blue-700">3</span>
                Jika jumlah event {"<"} maks per hari → tanggal tersedia → AI kirim booking link
              </li>
              <li className="flex items-start gap-2">
                <span className="mt-0.5 flex h-4 w-4 shrink-0 items-center justify-center rounded-full bg-blue-200 text-[9px] font-bold text-blue-700">4</span>
                Jika penuh atau terjadi error → handoff ke admin
              </li>
            </ul>
          </div>

          <div className="rounded-xl border border-slate-200 bg-white p-4">
            <h4 className="mb-2 text-sm font-semibold text-slate-700">Izin yang Diminta</h4>
            <p className="text-xs text-slate-500">
              Hanya akses <strong>baca kalender</strong> (read-only). Sistem tidak akan membuat, mengubah, atau menghapus event di Google Calendar kamu.
            </p>
          </div>

          {!isConnected && (
            <div className="rounded-xl border border-amber-100 bg-amber-50 p-4 text-xs text-amber-700">
              <p className="font-semibold mb-1">Belum Terhubung</p>
              <p>AI akan melakukan handoff ke admin setiap kali pelanggan meminta booking, karena tidak bisa memverifikasi ketersediaan tanggal.</p>
            </div>
          )}
        </div>
      </div>

      {/* Section 2 — Kapasitas */}
      <div className="rounded-xl border border-slate-200 bg-white p-5">
        <h3 className="mb-1 flex items-center gap-2 text-sm font-semibold text-slate-700">
          <Settings2 className="h-4 w-4 text-slate-400" /> Pengaturan Kapasitas
        </h3>
        <p className="mb-4 text-xs text-slate-400">
          Tentukan batas maksimal event per hari. Jika jumlah event di kalender sudah mencapai batas ini, AI akan menganggap tanggal tersebut tidak tersedia.
        </p>
        <form action="/tenant/calendar/settings" method="POST" className="flex items-end gap-3">
          <input type="hidden" name="_token" value={csrf} />
          <div>
            <label className="mb-1 block text-xs font-medium text-slate-600">
              Maksimal event per hari
            </label>
            <input
              type="number"
              name="max_events_per_date"
              defaultValue={currentMaxEvents}
              min="1"
              max="50"
              required
              className="w-32 rounded-lg border border-slate-200 bg-slate-50 px-3 py-2 text-sm text-slate-700 focus:border-emerald-400 focus:outline-none focus:ring-1 focus:ring-emerald-200"
            />
          </div>
          <button
            type="submit"
            className="flex items-center gap-2 rounded-lg bg-slate-800 px-4 py-2 text-sm font-semibold text-white transition hover:bg-slate-700"
          >
            <Save className="h-4 w-4" />
            Simpan
          </button>
        </form>
      </div>
    </div>
  )
}

// ─── Main page ─────────────────────────────────────────────────────────────────

export default function BusinessData({ data, assets, discountTypes = ['percentage', 'fixed'] }) {
  // eslint-disable-next-line no-unused-vars
  const page = usePage()
  const url = page?.url ?? '/tenant/business-data'
  const flash = page?.props?.flash ?? {}

  const currentStep = useMemo(() => {
    const query = url.includes('?') ? url.split('?')[1] : ''
    const params = new URLSearchParams(query)
    const step = params.get('step')
    return STEP_ORDER.includes(step) ? step : 'catalog'
  }, [url])

  const catalogs      = data?.serviceCatalogs ?? []
  const products      = data?.products ?? []
  const packages      = data?.packages ?? []
  const packageItems  = data?.packageItems ?? []
  const prices        = data?.prices ?? []
  const discounts     = data?.discounts ?? []
  const faqs          = data?.faqs ?? []
  const uploadedAssets = assets ?? []
  const bookingSettings = data?.bookingSettings ?? []
  const businessHoursPolicy = data?.businessHoursPolicy ?? {
    enabled: false, timezone: 'Asia/Jakarta', start_time: '09:00', end_time: '17:00', days: ['mon','tue','wed','thu','fri'],
  }
  const calendarConnection = data?.calendarConnection ?? { status: 'disconnected', is_enabled: false, email: null, calendar_id: 'primary' }
  const calendarSettings   = data?.calendarSettings ?? { max_events_per_date: 1 }

  const gate = {
    catalog: true,
    product: catalogs.length > 0,
    package: products.length > 0,
    package_items: packages.length > 0,
    pricing: packages.length > 0,
    faq: true,
    assets: true,
    settings: true,
    calendar: true,
  }

  const progress = {
    catalog: catalogs.length > 0,
    product: products.length > 0,
    package: packages.length > 0,
    package_items: packageItems.length > 0,
    pricing: prices.length > 0 || discounts.length > 0,
    faq: faqs.length > 0,
    assets: uploadedAssets.length > 0,
    settings: bookingSettings.length > 0 || businessHoursPolicy.enabled,
    calendar: calendarConnection?.status === 'connected' && calendarConnection?.is_enabled === true,
  }

  const resolvedStep = gate[currentStep] ? currentStep : STEP_ORDER.find((s) => gate[s]) ?? 'catalog'
  const meta = STEP_META[resolvedStep]
  const StepIcon = meta.icon

  const goToStep = (key) => {
    if (!STEP_ORDER.includes(key) || !gate[key]) return
    router.get(`/tenant/business-data?step=${key}`, {}, { preserveState: true, preserveScroll: true, replace: true })
  }

  const steps = STEP_ORDER.map((key, i) => ({
    key,
    index: i + 1,
    label: STEP_META[key].label,
    unlocked: gate[key],
    done: progress[key],
    active: resolvedStep === key,
  }))

  const doneCount = steps.filter((s) => s.done).length
  const totalSteps = steps.length

  // forms
  const catalogForm      = useForm({ name: '', description: '', sort_order: '' })
  const productForm      = useForm({ service_catalog_id: catalogs[0]?.id ? String(catalogs[0].id) : '', name: '', description: '', sort_order: '' })
  const packageForm      = useForm({ product_id: products[0]?.id ? String(products[0].id) : '', name: '', description: '', sort_order: '' })
  const packageItemForm  = useForm({ package_id: packages[0]?.id ? String(packages[0].id) : '', name: '', description: '', sort_order: '' })
  const priceForm        = useForm({ package_id: packages[0]?.id ? String(packages[0].id) : '', label: '', currency: 'IDR', amount: '', sort_order: '' })
  const discountForm     = useForm({ package_id: packages[0]?.id ? String(packages[0].id) : '', name: '', discount_type: discountTypes[0] ?? 'percentage', value: '', sort_order: '' })
  const faqForm          = useForm({ question: '', answer: '', sort_order: '' })
  const pricelistForm    = useForm({ display_name: '', file: null })
  const invoiceForm      = useForm({ display_name: '', file: null })
  const bookingForm      = useForm({
    booking_url: bookingSettings[0]?.booking_url ?? '',
    is_active: bookingSettings[0]?.is_active ?? true,
    active_from: bookingSettings[0]?.active_from ? String(bookingSettings[0].active_from).slice(0, 10) : '',
    active_until: bookingSettings[0]?.active_until ? String(bookingSettings[0].active_until).slice(0, 10) : '',
  })
  const businessHoursForm = useForm({
    enabled: businessHoursPolicy.enabled ?? false,
    timezone: businessHoursPolicy.timezone ?? 'Asia/Jakarta',
    start_time: businessHoursPolicy.start_time ?? '09:00',
    end_time: businessHoursPolicy.end_time ?? '17:00',
    days: Array.isArray(businessHoursPolicy.days) && businessHoursPolicy.days.length ? businessHoursPolicy.days : ['mon','tue','wed','thu','fri'],
  })

  // Inertia's useForm initializes once. When a parent list (catalogs/products/packages) was
  // empty on first render but later gets populated, the default <select> value stays empty —
  // the dropdown VISUALLY shows the first option but submits an empty string, which fails
  // the `required` rule silently. Sync the form's foreign-key field whenever the list grows.
  useEffect(() => {
    if (!productForm.data.service_catalog_id && catalogs[0]?.id) {
      productForm.setData('service_catalog_id', String(catalogs[0].id))
    }
  }, [catalogs])
  useEffect(() => {
    if (!packageForm.data.product_id && products[0]?.id) {
      packageForm.setData('product_id', String(products[0].id))
    }
  }, [products])
  useEffect(() => {
    if (!packageItemForm.data.package_id && packages[0]?.id) {
      packageItemForm.setData('package_id', String(packages[0].id))
    }
    if (!priceForm.data.package_id && packages[0]?.id) {
      priceForm.setData('package_id', String(packages[0].id))
    }
    if (!discountForm.data.package_id && packages[0]?.id) {
      discountForm.setData('package_id', String(packages[0].id))
    }
  }, [packages])

  const submitCatalog       = (e) => { e.preventDefault(); catalogForm.post('/tenant/business-data/service-catalogs', { preserveScroll: true, onSuccess: () => catalogForm.reset() }) }
  const submitProduct       = (e) => { e.preventDefault(); productForm.post('/tenant/business-data/products', { preserveScroll: true, onSuccess: () => productForm.reset('name', 'description', 'sort_order') }) }
  const submitPackage       = (e) => { e.preventDefault(); packageForm.post('/tenant/business-data/packages', { preserveScroll: true, onSuccess: () => packageForm.reset('name', 'description', 'sort_order') }) }
  const submitPackageItem   = (e) => { e.preventDefault(); packageItemForm.post('/tenant/business-data/package-items', { preserveScroll: true, onSuccess: () => packageItemForm.reset('name', 'description', 'sort_order') }) }
  const submitPrice         = (e) => { e.preventDefault(); priceForm.post('/tenant/business-data/prices', { preserveScroll: true, onSuccess: () => priceForm.reset('label', 'amount', 'sort_order') }) }
  const submitDiscount      = (e) => { e.preventDefault(); discountForm.post('/tenant/business-data/discounts', { preserveScroll: true, onSuccess: () => discountForm.reset('name', 'value', 'sort_order') }) }
  const submitFaq           = (e) => { e.preventDefault(); faqForm.post('/tenant/business-data/faqs', { preserveScroll: true, onSuccess: () => faqForm.reset() }) }
  const submitPricelist     = (e) => { e.preventDefault(); pricelistForm.post('/tenant/business-data/assets/pricelist', { preserveScroll: true, forceFormData: true, onSuccess: () => pricelistForm.reset() }) }
  const submitInvoiceAsset  = (e) => { e.preventDefault(); invoiceForm.post('/tenant/business-data/assets/invoice', { preserveScroll: true, forceFormData: true, onSuccess: () => invoiceForm.reset() }) }
  const submitBookingSetting   = (e) => { e.preventDefault(); bookingForm.post('/tenant/business-data/booking-setting', { preserveScroll: true }) }
  const submitBusinessHours    = (e) => { e.preventDefault(); businessHoursForm.post('/tenant/business-data/business-hours', { preserveScroll: true }) }

  const prevStep = STEP_ORDER[STEP_ORDER.indexOf(resolvedStep) - 1]
  const nextStep = meta.next

  return (
    <TenantLayout title="Data Bisnis">
      {/* Progress header */}
      <div className="rounded-xl border border-slate-200 bg-white px-5 py-4">
        <div className="mb-1 flex items-center justify-between">
          <p className="text-sm font-medium text-slate-700">Kelengkapan Data Bisnis</p>
          <span className="text-sm font-semibold text-emerald-600">{doneCount} / {totalSteps} langkah selesai</span>
        </div>
        <div className="mb-4 h-2 overflow-hidden rounded-full bg-slate-100">
          <div
            className="h-full rounded-full bg-emerald-500 transition-all duration-500"
            style={{ width: `${(doneCount / totalSteps) * 100}%` }}
          />
        </div>
        <Stepper steps={steps} onSelect={goToStep} />
      </div>

      {/* Flash messages */}
      {(flash.success || flash.error) && (
        <div className="mt-3">
          {flash.success && (
            <div className="flex items-center gap-2 rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800">
              <CheckCircle2 className="h-4 w-4 shrink-0" /> {flash.success}
            </div>
          )}
          {flash.error && (
            <div className="flex items-center gap-2 rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">
              <AlertCircle className="h-4 w-4 shrink-0" /> {flash.error}
            </div>
          )}
        </div>
      )}

      {/* Active step card */}
      <div className="mt-4 rounded-xl border border-slate-200 bg-white">
        {/* Step header */}
        <div className="flex items-center justify-between border-b border-slate-100 px-5 py-4">
          <div className="flex items-center gap-3">
            <div className={`flex h-9 w-9 items-center justify-center rounded-xl ${progress[resolvedStep] ? 'bg-emerald-100' : 'bg-slate-100'}`}>
              <StepIcon className={`h-4 w-4 ${progress[resolvedStep] ? 'text-emerald-600' : 'text-slate-500'}`} />
            </div>
            <div>
              <h2 className="font-semibold text-slate-800">{meta.title}</h2>
              <p className="text-xs text-slate-500">{meta.description}</p>
            </div>
          </div>
          {progress[resolvedStep] && (
            <span className="flex items-center gap-1 rounded-full bg-emerald-100 px-2.5 py-1 text-xs font-semibold text-emerald-700">
              <CheckCircle2 className="h-3 w-3" /> Selesai
            </span>
          )}
        </div>

        {/* Hint */}
        {meta.hint && (
          <div className="border-b border-slate-50 px-5 py-2.5">
            <p className="flex items-center gap-1.5 text-xs text-slate-400">
              <Info className="h-3.5 w-3.5 shrink-0 text-slate-300" /> {meta.hint}
            </p>
          </div>
        )}

        {/* Step content */}
        <div className="p-5">
          {resolvedStep === 'catalog'       && <CatalogStep form={catalogForm} rows={catalogs} onSubmit={submitCatalog} />}
          {resolvedStep === 'product'       && <ProductStep form={productForm} catalogs={catalogs} rows={products} onSubmit={submitProduct} disabled={!gate.product} />}
          {resolvedStep === 'package'       && <PackageStep form={packageForm} products={products} rows={packages} onSubmit={submitPackage} disabled={!gate.package} />}
          {resolvedStep === 'package_items' && <PackageItemsStep form={packageItemForm} packages={packages} rows={packageItems} onSubmit={submitPackageItem} disabled={!gate.package_items} />}
          {resolvedStep === 'pricing'       && <PricingStep priceForm={priceForm} discountForm={discountForm} packages={packages} prices={prices} discounts={discounts} discountTypes={discountTypes} onSubmitPrice={submitPrice} onSubmitDiscount={submitDiscount} disabled={!gate.pricing} />}
          {resolvedStep === 'faq'           && <FaqStep form={faqForm} rows={faqs} onSubmit={submitFaq} />}
          {resolvedStep === 'assets'        && <AssetsStep pricelistForm={pricelistForm} invoiceForm={invoiceForm} rows={uploadedAssets} onSubmitPricelist={submitPricelist} onSubmitInvoice={submitInvoiceAsset} />}
          {resolvedStep === 'settings'      && <SettingsStep bookingForm={bookingForm} businessHoursForm={businessHoursForm} bookingRows={bookingSettings} onSubmitBooking={submitBookingSetting} onSubmitBusinessHours={submitBusinessHours} />}
          {resolvedStep === 'calendar'      && <CalendarStep calendarConnection={calendarConnection} calendarSettings={calendarSettings} />}
        </div>

        {/* Step navigation */}
        <div className="flex items-center justify-between border-t border-slate-100 px-5 py-3">
          <button
            type="button"
            onClick={() => prevStep && goToStep(prevStep)}
            disabled={!prevStep}
            className="flex items-center gap-1.5 rounded-lg px-3 py-2 text-sm font-medium text-slate-500 transition hover:bg-slate-50 disabled:cursor-not-allowed disabled:opacity-30"
          >
            <ChevronLeft className="h-4 w-4" /> Sebelumnya
          </button>

          {nextStep && gate[nextStep] ? (
            <button
              type="button"
              onClick={() => goToStep(nextStep)}
              className="flex items-center gap-1.5 rounded-lg bg-emerald-600 px-4 py-2 text-sm font-semibold text-white transition hover:bg-emerald-700"
            >
              {meta.cta} <ChevronRight className="h-4 w-4" />
            </button>
          ) : nextStep && !gate[nextStep] ? (
            <span className="flex items-center gap-1.5 rounded-lg border border-slate-200 px-4 py-2 text-sm text-slate-400">
              <Lock className="h-3.5 w-3.5" /> {meta.cta}
            </span>
          ) : (
            <span className="flex items-center gap-1.5 rounded-full bg-emerald-50 px-4 py-2 text-sm font-medium text-emerald-600">
              <CheckCircle2 className="h-4 w-4" /> Data bisnis lengkap!
            </span>
          )}
        </div>
      </div>
    </TenantLayout>
  )
}
