import React, { useMemo } from 'react'
import { router, useForm, usePage } from '@inertiajs/react'
import TenantLayout from '../../layouts/TenantLayout'
import { Button } from '../../components/ui/button'
import { Input } from '../../components/ui/input'
import { Select } from '../../components/ui/select'
import { Badge } from '../../components/ui/badge'
import { ArrowRight, CheckCircle2, Lock, Save, Upload, Plus, Trash2 } from 'lucide-react'

const STEP_ORDER = ['catalog', 'product', 'package', 'package_items', 'pricing', 'faq', 'assets', 'settings']

const DAY_OPTIONS = [
  { key: 'mon', label: 'Senin' },
  { key: 'tue', label: 'Selasa' },
  { key: 'wed', label: 'Rabu' },
  { key: 'thu', label: 'Kamis' },
  { key: 'fri', label: 'Jumat' },
  { key: 'sat', label: 'Sabtu' },
  { key: 'sun', label: 'Minggu' },
]

export default function BusinessData({ data, assets, discountTypes = ['percentage', 'fixed'] }) {
  const page = usePage()
  const url = page?.url ?? '/tenant/business-data'
  const flash = page?.props?.flash ?? {}

  const currentStep = useMemo(() => {
    const query = url.includes('?') ? url.split('?')[1] : ''
    const params = new URLSearchParams(query)
    const step = params.get('step')
    return STEP_ORDER.includes(step) ? step : 'catalog'
  }, [url])

  const catalogs = data?.serviceCatalogs ?? []
  const products = data?.products ?? []
  const packages = data?.packages ?? []
  const packageItems = data?.packageItems ?? []
  const prices = data?.prices ?? []
  const discounts = data?.discounts ?? []
  const faqs = data?.faqs ?? []
  const uploadedAssets = assets ?? []
  const bookingSettings = data?.bookingSettings ?? []
  const businessHoursPolicy = data?.businessHoursPolicy ?? {
    enabled: false,
    timezone: 'Asia/Jakarta',
    start_time: '09:00',
    end_time: '17:00',
    days: ['mon', 'tue', 'wed', 'thu', 'fri'],
  }

  const gate = {
    catalog: true,
    product: catalogs.length > 0,
    package: products.length > 0,
    package_items: packages.length > 0,
    pricing: packages.length > 0,
    faq: true,
    assets: true,
    settings: true,
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
  }

  const stepMeta = {
    catalog: { title: 'Langkah 1: Katalog Layanan', description: 'Mulai dengan kategori layanan utama bisnis.', emptyHint: 'Buat minimal 1 katalog layanan dulu sebelum menambahkan produk.', cta: 'Lanjut ke Produk', next: 'product' },
    product: { title: 'Langkah 2: Produk', description: 'Tambahkan produk turunan dari katalog layanan.', emptyHint: 'Pilih katalog layanan induk saat membuat produk.', cta: 'Lanjut ke Paket', next: 'package' },
    package: { title: 'Langkah 3: Paket', description: 'Buat paket penawaran untuk kebutuhan harga dan diskon.', emptyHint: 'Produk harus tersedia sebelum membuat paket.', cta: 'Lanjut ke Item Paket', next: 'package_items' },
    package_items: { title: 'Langkah 4: Item Paket', description: 'Daftarkan item/fasilitas yang termasuk dalam setiap paket. AI akan menyebut ini saat menjelaskan paket kepada prospek.', emptyHint: 'Tambahkan item dalam paket agar AI bisa menjelaskan detail paket dengan akurat.', cta: 'Lanjut ke Harga & Diskon', next: 'pricing' },
    pricing: { title: 'Langkah 5: Harga & Diskon', description: 'Isi harga dan promo agar jawaban AI tetap grounded.', emptyHint: 'Buat minimal 1 harga atau diskon untuk paket aktif.', cta: 'Lanjut ke FAQ', next: 'faq' },
    faq: { title: 'Langkah 6: FAQ', description: 'Siapkan jawaban standar untuk pertanyaan berulang.', emptyHint: 'Tambahkan FAQ untuk memperkuat konsistensi jawaban AI.', cta: 'Lanjut ke Aset', next: 'assets' },
    assets: { title: 'Langkah 7: Aset', description: 'Unggah dokumen pendukung agar flow siap end-to-end.', emptyHint: 'Unggah PDF daftar harga/invoice sebagai dokumen resmi tenant.', cta: 'Lanjut ke Pengaturan Operasional', next: 'settings' },
    settings: { title: 'Langkah 8: Pengaturan Operasional', description: 'Atur booking link dan jam operasional agar kebijakan AI konsisten dengan SOP tenant.', emptyHint: 'Isi booking link aktif dan jam operasional bisnis.', cta: null, next: null },
  }

  const goToStep = (stepKey) => {
    if (!STEP_ORDER.includes(stepKey) || !gate[stepKey]) return
    router.get(`/tenant/business-data?step=${stepKey}`, {}, { preserveState: true, preserveScroll: true, replace: true })
  }

  const catalogForm = useForm({ name: '', description: '', sort_order: '' })
  const productForm = useForm({ service_catalog_id: catalogs[0]?.id ? String(catalogs[0].id) : '', name: '', description: '', sort_order: '' })
  const packageForm = useForm({ product_id: products[0]?.id ? String(products[0].id) : '', name: '', description: '', sort_order: '' })
  const packageItemForm = useForm({ package_id: packages[0]?.id ? String(packages[0].id) : '', name: '', description: '', sort_order: '' })
  const priceForm = useForm({ package_id: packages[0]?.id ? String(packages[0].id) : '', label: '', currency: 'IDR', amount: '', sort_order: '' })
  const discountForm = useForm({ package_id: packages[0]?.id ? String(packages[0].id) : '', name: '', discount_type: discountTypes[0] ?? 'percentage', value: '', sort_order: '' })
  const faqForm = useForm({ question: '', answer: '', sort_order: '' })
  const pricelistForm = useForm({ display_name: '', file: null })
  const invoiceForm = useForm({ display_name: '', file: null })
  const bookingForm = useForm({
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
    days: Array.isArray(businessHoursPolicy.days) && businessHoursPolicy.days.length ? businessHoursPolicy.days : ['mon', 'tue', 'wed', 'thu', 'fri'],
  })

  const resolvedStep = gate[currentStep] ? currentStep : STEP_ORDER.find((step) => gate[step]) ?? 'catalog'
  const activeStepMeta = stepMeta[resolvedStep]

  const submitCatalog = (e) => { e.preventDefault(); catalogForm.post('/tenant/business-data/service-catalogs', { preserveScroll: true, onSuccess: () => catalogForm.reset() }) }
  const submitProduct = (e) => { e.preventDefault(); productForm.post('/tenant/business-data/products', { preserveScroll: true, onSuccess: () => productForm.reset('name', 'description', 'sort_order') }) }
  const submitPackage = (e) => { e.preventDefault(); packageForm.post('/tenant/business-data/packages', { preserveScroll: true, onSuccess: () => packageForm.reset('name', 'description', 'sort_order') }) }
  const submitPackageItem = (e) => { e.preventDefault(); packageItemForm.post('/tenant/business-data/package-items', { preserveScroll: true, onSuccess: () => packageItemForm.reset('name', 'description', 'sort_order') }) }
  const submitPrice = (e) => { e.preventDefault(); priceForm.post('/tenant/business-data/prices', { preserveScroll: true, onSuccess: () => priceForm.reset('label', 'amount', 'sort_order') }) }
  const submitDiscount = (e) => { e.preventDefault(); discountForm.post('/tenant/business-data/discounts', { preserveScroll: true, onSuccess: () => discountForm.reset('name', 'value', 'sort_order') }) }
  const submitFaq = (e) => { e.preventDefault(); faqForm.post('/tenant/business-data/faqs', { preserveScroll: true, onSuccess: () => faqForm.reset() }) }
  const submitPricelist = (e) => { e.preventDefault(); pricelistForm.post('/tenant/business-data/assets/pricelist', { preserveScroll: true, forceFormData: true, onSuccess: () => pricelistForm.reset() }) }
  const submitInvoiceAsset = (e) => { e.preventDefault(); invoiceForm.post('/tenant/business-data/assets/invoice', { preserveScroll: true, forceFormData: true, onSuccess: () => invoiceForm.reset() }) }
  const submitBookingSetting = (e) => { e.preventDefault(); bookingForm.post('/tenant/business-data/booking-setting', { preserveScroll: true }) }
  const submitBusinessHours = (e) => { e.preventDefault(); businessHoursForm.post('/tenant/business-data/business-hours', { preserveScroll: true }) }

  const steps = STEP_ORDER.map((key, index) => ({
    key,
    index: index + 1,
    label: stepMeta[key].title.replace(/^Langkah\s\d+:\s/, ''),
    unlocked: gate[key],
    done: progress[key],
    active: resolvedStep === key,
  }))

  return (
    <TenantLayout title="Pengelolaan Data Bisnis">
      <div className="rounded-xl border border-slate-200 bg-white p-4">
        <p className="text-sm text-slate-600">Ikuti langkah berurutan agar data bisnis siap dipakai AI sales engine secara end-to-end.</p>
        <StepNav steps={steps} onSelect={goToStep} />
      </div>

      <div className="mt-4">
        {flash.success && <div className="mb-3 rounded-lg border border-emerald-200 bg-emerald-50 px-3 py-2 text-sm text-emerald-800">{flash.success}</div>}
        {flash.error && <div className="mb-3 rounded-lg border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-700">{flash.error}</div>}

        {resolvedStep !== 'settings' && (
          <div className="mb-4 rounded-xl border border-emerald-200 bg-emerald-50/40 p-4">
            <div className="mb-3 flex flex-wrap items-center justify-between gap-2">
              <h2 className="text-base font-semibold text-emerald-900">Quick Config Operasional</h2>
              <Button type="button" onClick={() => goToStep('settings')}>Buka Pengaturan</Button>
            </div>
            <SettingsStep
              bookingForm={bookingForm}
              businessHoursForm={businessHoursForm}
              bookingRows={bookingSettings}
              onSubmitBooking={submitBookingSetting}
              onSubmitBusinessHours={submitBusinessHours}
            />
          </div>
        )}

        <StepCard title={activeStepMeta.title} description={activeStepMeta.description} isDone={progress[resolvedStep]} emptyHint={activeStepMeta.emptyHint} hasData={progress[resolvedStep]}>
          {resolvedStep === 'catalog' && <CatalogStep form={catalogForm} rows={catalogs} onSubmit={submitCatalog} />}
          {resolvedStep === 'product' && <ProductStep form={productForm} catalogs={catalogs} rows={products} onSubmit={submitProduct} disabled={!gate.product} />}
          {resolvedStep === 'package' && <PackageStep form={packageForm} products={products} rows={packages} onSubmit={submitPackage} disabled={!gate.package} />}
          {resolvedStep === 'package_items' && (
            <PackageItemsStep
              form={packageItemForm}
              packages={packages}
              rows={packageItems}
              onSubmit={submitPackageItem}
              disabled={!gate.package_items}
            />
          )}
          {resolvedStep === 'pricing' && (
            <PricingStep
              priceForm={priceForm}
              discountForm={discountForm}
              packages={packages}
              prices={prices}
              discounts={discounts}
              discountTypes={discountTypes}
              onSubmitPrice={submitPrice}
              onSubmitDiscount={submitDiscount}
              disabled={!gate.pricing}
            />
          )}
          {resolvedStep === 'faq' && <FaqStep form={faqForm} rows={faqs} onSubmit={submitFaq} />}
          {resolvedStep === 'assets' && (
            <AssetsStep
              pricelistForm={pricelistForm}
              invoiceForm={invoiceForm}
              rows={uploadedAssets}
              onSubmitPricelist={submitPricelist}
              onSubmitInvoice={submitInvoiceAsset}
            />
          )}
          {resolvedStep === 'settings' && (
            <SettingsStep
              bookingForm={bookingForm}
              businessHoursForm={businessHoursForm}
              bookingRows={bookingSettings}
              onSubmitBooking={submitBookingSetting}
              onSubmitBusinessHours={submitBusinessHours}
            />
          )}
        </StepCard>

        {activeStepMeta.next && gate[activeStepMeta.next] && progress[resolvedStep] && (
          <div className="mt-3 flex justify-end">
            <Button type="button" rightIcon={ArrowRight} onClick={() => goToStep(activeStepMeta.next)}>
              {activeStepMeta.cta}
            </Button>
          </div>
        )}
      </div>
    </TenantLayout>
  )
}

function StepNav({ steps, onSelect }) {
  return (
    <ol className="mt-4 grid gap-2 md:grid-cols-2 xl:grid-cols-4">
      {steps.map((step) => (
        <li key={step.key}>
          <button
            type="button"
            onClick={() => onSelect(step.key)}
            disabled={!step.unlocked}
            className={`flex w-full items-center justify-between rounded-lg border px-3 py-2 text-left text-sm transition ${
              step.active ? 'border-emerald-400 bg-emerald-50' : 'border-slate-200 bg-white'
            } ${!step.unlocked ? 'cursor-not-allowed opacity-60' : 'hover:border-emerald-300'}`}
          >
            <span>
              <span className="mr-1 text-xs font-semibold uppercase text-slate-400">{step.index}.</span>
              <span className="font-medium text-slate-800">{step.label}</span>
            </span>
            {step.done ? (
              <Badge tone="success" className="inline-flex items-center gap-1">
                <CheckCircle2 className="h-3 w-3" /> Selesai
              </Badge>
            ) : step.unlocked ? (
              <Badge>Proses</Badge>
            ) : (
              <Badge tone="danger" className="inline-flex items-center gap-1">
                <Lock className="h-3 w-3" /> Terkunci
              </Badge>
            )}
          </button>
        </li>
      ))}
    </ol>
  )
}

function StepCard({ title, description, isDone, emptyHint, hasData, children }) {
  return (
    <div className="rounded-xl border border-slate-200 bg-white p-5">
      <div className="flex flex-wrap items-center justify-between gap-2">
        <h2 className="text-lg font-semibold text-slate-800">{title}</h2>
        <Badge tone={isDone ? 'success' : 'neutral'}>{isDone ? 'Selesai' : 'Sedang Proses'}</Badge>
      </div>
      <p className="mt-1 text-sm text-slate-500">{description}</p>
      {!hasData && <p className="mt-2 rounded-md bg-amber-50 px-3 py-2 text-sm text-amber-700">{emptyHint}</p>}
      <div className="mt-5">{children}</div>
    </div>
  )
}

function Field({ label, required = false, children }) {
  return (
    <label className="block space-y-1">
      <span className="text-sm font-medium text-slate-700">
        {label} {required && <span className="text-red-500">*</span>}
      </span>
      {children}
    </label>
  )
}

function FieldError({ message }) {
  return message ? <p className="text-xs text-red-600">{message}</p> : null
}

function InlineList({ title, rows, render, emptyText, onDelete }) {
  return (
    <div className="rounded-lg border border-slate-200 bg-slate-50 p-3">
      <h3 className="text-sm font-semibold text-slate-700">{title}</h3>
      <ul className="mt-2 space-y-1 text-sm">
        {(rows ?? []).map((row) => (
          <li key={row.id} className="flex items-center justify-between rounded border border-slate-100 bg-white px-2 py-1.5">
            <span className="text-slate-700">{render(row)}</span>
            {onDelete && (
              <button
                type="button"
                onClick={() => { if (confirm('Hapus item ini?')) onDelete(row.id) }}
                className="rounded p-1 text-slate-400 hover:bg-red-50 hover:text-red-600"
              >
                <Trash2 className="h-3.5 w-3.5" />
              </button>
            )}
          </li>
        ))}
        {(rows ?? []).length === 0 && <li className="text-slate-400">{emptyText}</li>}
      </ul>
    </div>
  )
}

function CatalogStep({ form, rows, onSubmit }) {
  return (
    <div className="grid gap-6 lg:grid-cols-2">
      <form className="space-y-4" onSubmit={onSubmit}>
        <Field label="Nama Katalog" required>
          <Input value={form.data.name} onChange={(e) => form.setData('name', e.target.value)} placeholder="Layanan Wedding" />
          <FieldError message={form.errors.name} />
        </Field>
        <Field label="Deskripsi">
          <textarea className="w-full rounded-md border border-slate-300 px-3 py-2 text-sm" value={form.data.description} onChange={(e) => form.setData('description', e.target.value)} rows={3} />
          <FieldError message={form.errors.description} />
        </Field>
        <Field label="Urutan">
          <Input type="number" min="0" value={form.data.sort_order} onChange={(e) => form.setData('sort_order', e.target.value)} />
        </Field>
        <Button type="submit" leftIcon={Save} disabled={form.processing}>Simpan Katalog</Button>
      </form>
      <InlineList title="Daftar Katalog" rows={rows} emptyText="Belum ada katalog layanan." render={(r) => `${r.name} (${r.code})`} />
    </div>
  )
}

function ProductStep({ form, catalogs, rows, onSubmit, disabled }) {
  return (
    <div className="grid gap-6 lg:grid-cols-2">
      <form className="space-y-4" onSubmit={onSubmit}>
        <Field label="Katalog Layanan" required>
          <Select value={form.data.service_catalog_id} onChange={(e) => form.setData('service_catalog_id', e.target.value)} disabled={disabled}>
            {!catalogs.length ? <option value="">Belum ada katalog</option> : catalogs.map((r) => <option key={r.id} value={r.id}>{r.name}</option>)}
          </Select>
        </Field>
        <Field label="Nama Produk" required>
          <Input value={form.data.name} onChange={(e) => form.setData('name', e.target.value)} disabled={disabled} />
          <FieldError message={form.errors.name} />
        </Field>
        <Field label="Deskripsi">
          <textarea className="w-full rounded-md border border-slate-300 px-3 py-2 text-sm" value={form.data.description} onChange={(e) => form.setData('description', e.target.value)} rows={3} disabled={disabled} />
        </Field>
        <Button type="submit" leftIcon={Save} disabled={disabled || form.processing}>Simpan Produk</Button>
      </form>
      <InlineList title="Daftar Produk" rows={rows} emptyText="Belum ada produk." render={(r) => `${r.name} (${r.code})`} />
    </div>
  )
}

function PackageStep({ form, products, rows, onSubmit, disabled }) {
  return (
    <div className="grid gap-6 lg:grid-cols-2">
      <form className="space-y-4" onSubmit={onSubmit}>
        <Field label="Produk" required>
          <Select value={form.data.product_id} onChange={(e) => form.setData('product_id', e.target.value)} disabled={disabled}>
            {!products.length ? <option value="">Belum ada produk</option> : products.map((r) => <option key={r.id} value={r.id}>{r.name}</option>)}
          </Select>
        </Field>
        <Field label="Nama Paket" required>
          <Input value={form.data.name} onChange={(e) => form.setData('name', e.target.value)} disabled={disabled} />
          <FieldError message={form.errors.name} />
        </Field>
        <Field label="Deskripsi">
          <textarea className="w-full rounded-md border border-slate-300 px-3 py-2 text-sm" value={form.data.description} onChange={(e) => form.setData('description', e.target.value)} rows={3} disabled={disabled} />
        </Field>
        <Button type="submit" leftIcon={Save} disabled={disabled || form.processing}>Simpan Paket</Button>
      </form>
      <InlineList title="Daftar Paket" rows={rows} emptyText="Belum ada paket." render={(r) => `${r.name} (${r.code})`} />
    </div>
  )
}

function PackageItemsStep({ form, packages, rows, onSubmit, disabled }) {
  const itemsByPackage = packages.map((pkg) => ({
    ...pkg,
    items: rows.filter((item) => item.package_id === pkg.id),
  }))

  return (
    <div className="grid gap-6 lg:grid-cols-2">
      <form className="space-y-4" onSubmit={onSubmit}>
        <div className="rounded-lg border border-blue-100 bg-blue-50 px-3 py-2 text-xs text-blue-700">
          Item paket adalah fasilitas/layanan yang termasuk dalam satu paket. Contoh: "Dokumentasi 8 jam", "2 Fotografer", "Album Premium". AI akan menyebutnya saat menjelaskan paket kepada prospek.
        </div>
        <Field label="Pilih Paket" required>
          <Select value={form.data.package_id} onChange={(e) => form.setData('package_id', e.target.value)} disabled={disabled}>
            {!packages.length ? <option value="">Belum ada paket</option> : packages.map((r) => <option key={r.id} value={r.id}>{r.name}</option>)}
          </Select>
          <FieldError message={form.errors.package_id} />
        </Field>
        <Field label="Nama Item" required>
          <Input value={form.data.name} onChange={(e) => form.setData('name', e.target.value)} placeholder="Contoh: Dokumentasi 8 jam" disabled={disabled} />
          <FieldError message={form.errors.name} />
        </Field>
        <Field label="Keterangan">
          <Input value={form.data.description} onChange={(e) => form.setData('description', e.target.value)} placeholder="Detail tambahan (opsional)" disabled={disabled} />
        </Field>
        <Field label="Urutan">
          <Input type="number" min="0" value={form.data.sort_order} onChange={(e) => form.setData('sort_order', e.target.value)} disabled={disabled} />
        </Field>
        <Button type="submit" leftIcon={Plus} disabled={disabled || form.processing}>Tambah Item</Button>
      </form>

      <div className="space-y-4">
        {itemsByPackage.map((pkg) => (
          <div key={pkg.id} className="rounded-lg border border-slate-200 bg-slate-50 p-3">
            <h4 className="mb-2 text-sm font-semibold text-slate-700">{pkg.name}</h4>
            {pkg.items.length === 0 ? (
              <p className="text-xs text-slate-400">Belum ada item untuk paket ini.</p>
            ) : (
              <ul className="space-y-1">
                {pkg.items.map((item) => (
                  <li key={item.id} className="flex items-center justify-between rounded border border-slate-100 bg-white px-2 py-1.5 text-sm">
                    <span className="text-slate-700">{item.name}</span>
                    <button
                      type="button"
                      onClick={() => { if (confirm('Hapus item ini?')) router.delete(`/tenant/business-data/package-items/${item.id}`, { preserveScroll: true }) }}
                      className="rounded p-1 text-slate-400 hover:bg-red-50 hover:text-red-600"
                    >
                      <Trash2 className="h-3.5 w-3.5" />
                    </button>
                  </li>
                ))}
              </ul>
            )}
          </div>
        ))}
        {packages.length === 0 && <p className="text-sm text-slate-400">Buat paket terlebih dahulu.</p>}
      </div>
    </div>
  )
}

function PricingStep({ priceForm, discountForm, packages, prices, discounts, discountTypes, onSubmitPrice, onSubmitDiscount, disabled }) {
  return (
    <div className="grid gap-6 lg:grid-cols-2">
      <div className="space-y-5">
        <form className="space-y-3 rounded-lg border border-slate-200 p-4" onSubmit={onSubmitPrice}>
          <h3 className="font-semibold text-slate-700">Buat Harga</h3>
          <Field label="Paket" required>
            <Select value={priceForm.data.package_id} onChange={(e) => priceForm.setData('package_id', e.target.value)} disabled={disabled}>
              {!packages.length ? <option value="">Belum ada paket</option> : packages.map((r) => <option key={r.id} value={r.id}>{r.name}</option>)}
            </Select>
          </Field>
          <Field label="Label Harga" required>
            <Input value={priceForm.data.label} onChange={(e) => priceForm.setData('label', e.target.value)} disabled={disabled} />
            <FieldError message={priceForm.errors.label} />
          </Field>
          <div className="grid gap-3 sm:grid-cols-2">
            <Field label="Mata Uang" required><Input value={priceForm.data.currency} onChange={(e) => priceForm.setData('currency', e.target.value)} disabled={disabled} /></Field>
            <Field label="Nominal" required><Input type="number" min="0" value={priceForm.data.amount} onChange={(e) => priceForm.setData('amount', e.target.value)} disabled={disabled} /></Field>
          </div>
          <Button type="submit" leftIcon={Save} disabled={disabled || priceForm.processing}>Simpan Harga</Button>
        </form>

        <form className="space-y-3 rounded-lg border border-slate-200 p-4" onSubmit={onSubmitDiscount}>
          <h3 className="font-semibold text-slate-700">Buat Diskon</h3>
          <Field label="Paket" required>
            <Select value={discountForm.data.package_id} onChange={(e) => discountForm.setData('package_id', e.target.value)} disabled={disabled}>
              {!packages.length ? <option value="">Belum ada paket</option> : packages.map((r) => <option key={r.id} value={r.id}>{r.name}</option>)}
            </Select>
          </Field>
          <Field label="Nama Diskon" required>
            <Input value={discountForm.data.name} onChange={(e) => discountForm.setData('name', e.target.value)} disabled={disabled} />
            <FieldError message={discountForm.errors.name} />
          </Field>
          <div className="grid gap-3 sm:grid-cols-2">
            <Field label="Jenis">
              <Select value={discountForm.data.discount_type} onChange={(e) => discountForm.setData('discount_type', e.target.value)} disabled={disabled}>
                {discountTypes.map((t) => <option key={t} value={t}>{t}</option>)}
              </Select>
            </Field>
            <Field label="Nilai" required><Input type="number" min="0" value={discountForm.data.value} onChange={(e) => discountForm.setData('value', e.target.value)} disabled={disabled} /></Field>
          </div>
          <Button type="submit" leftIcon={Save} disabled={disabled || discountForm.processing}>Simpan Diskon</Button>
        </form>
      </div>

      <div className="space-y-4">
        <InlineList title="Daftar Harga" rows={prices} emptyText="Belum ada harga." render={(r) => `${r.label} — ${r.currency} ${Number(r.amount).toLocaleString('id-ID')}`} />
        <InlineList title="Daftar Diskon" rows={discounts} emptyText="Belum ada diskon." render={(r) => `${r.name} (${r.discount_type}: ${r.value})`} />
      </div>
    </div>
  )
}

function FaqStep({ form, rows, onSubmit }) {
  return (
    <div className="grid gap-6 lg:grid-cols-2">
      <form className="space-y-4" onSubmit={onSubmit}>
        <Field label="Pertanyaan" required>
          <Input value={form.data.question} onChange={(e) => form.setData('question', e.target.value)} />
          <FieldError message={form.errors.question} />
        </Field>
        <Field label="Jawaban" required>
          <textarea className="w-full rounded-md border border-slate-300 px-3 py-2 text-sm" value={form.data.answer} onChange={(e) => form.setData('answer', e.target.value)} rows={4} />
          <FieldError message={form.errors.answer} />
        </Field>
        <Field label="Urutan"><Input type="number" min="0" value={form.data.sort_order} onChange={(e) => form.setData('sort_order', e.target.value)} /></Field>
        <Button type="submit" leftIcon={Save} disabled={form.processing}>Simpan FAQ</Button>
      </form>
      <InlineList title="Daftar FAQ" rows={rows} emptyText="Belum ada FAQ." render={(r) => r.question} />
    </div>
  )
}

function AssetsStep({ pricelistForm, invoiceForm, rows, onSubmitPricelist, onSubmitInvoice }) {
  return (
    <div className="grid gap-6 lg:grid-cols-2">
      <div className="space-y-5">
        <form className="space-y-3 rounded-lg border border-slate-200 p-4" onSubmit={onSubmitPricelist}>
          <h3 className="font-semibold text-slate-700">Unggah PDF Daftar Harga</h3>
          <Field label="Nama Tampilan"><Input value={pricelistForm.data.display_name} onChange={(e) => pricelistForm.setData('display_name', e.target.value)} /></Field>
          <Field label="Berkas PDF" required><Input type="file" accept="application/pdf" onChange={(e) => pricelistForm.setData('file', e.target.files?.[0] ?? null)} /><FieldError message={pricelistForm.errors.file} /></Field>
          <Button type="submit" leftIcon={Upload} disabled={pricelistForm.processing}>Unggah Daftar Harga</Button>
        </form>
        <form className="space-y-3 rounded-lg border border-slate-200 p-4" onSubmit={onSubmitInvoice}>
          <h3 className="font-semibold text-slate-700">Unggah PDF Template Invoice</h3>
          <Field label="Nama Tampilan"><Input value={invoiceForm.data.display_name} onChange={(e) => invoiceForm.setData('display_name', e.target.value)} /></Field>
          <Field label="Berkas PDF" required><Input type="file" accept="application/pdf" onChange={(e) => invoiceForm.setData('file', e.target.files?.[0] ?? null)} /><FieldError message={invoiceForm.errors.file} /></Field>
          <Button type="submit" leftIcon={Upload} disabled={invoiceForm.processing}>Unggah Invoice</Button>
        </form>
      </div>
      <InlineList title="Aset Terunggah" rows={rows} emptyText="Belum ada aset." render={(r) => `[${r.asset_type}] ${r.display_name || r.original_filename}`} />
    </div>
  )
}

function SettingsStep({ bookingForm, businessHoursForm, bookingRows, onSubmitBooking, onSubmitBusinessHours }) {
  const toggleDay = (dayKey) => {
    const days = Array.isArray(businessHoursForm.data.days) ? businessHoursForm.data.days : []
    businessHoursForm.setData('days', days.includes(dayKey) ? days.filter((v) => v !== dayKey) : [...days, dayKey])
  }

  return (
    <div className="grid gap-6 lg:grid-cols-2">
      <div className="space-y-5">
        <form className="space-y-4 rounded-lg border border-slate-200 p-4" onSubmit={onSubmitBooking}>
          <h3 className="font-semibold text-slate-700">Booking Link</h3>
          <Field label="Booking URL" required>
            <Input type="url" placeholder="https://booking.example.com/wedding" value={bookingForm.data.booking_url} onChange={(e) => bookingForm.setData('booking_url', e.target.value)} />
            <FieldError message={bookingForm.errors.booking_url} />
          </Field>
          <div className="grid gap-3 sm:grid-cols-2">
            <Field label="Aktif Dari"><Input type="date" value={bookingForm.data.active_from} onChange={(e) => bookingForm.setData('active_from', e.target.value)} /></Field>
            <Field label="Aktif Sampai"><Input type="date" value={bookingForm.data.active_until} onChange={(e) => bookingForm.setData('active_until', e.target.value)} /></Field>
          </div>
          <label className="flex items-center gap-2 text-sm text-slate-700">
            <input type="checkbox" checked={bookingForm.data.is_active === true} onChange={(e) => bookingForm.setData('is_active', e.target.checked)} />
            Aktifkan booking link
          </label>
          <Button type="submit" leftIcon={Save} disabled={bookingForm.processing}>Simpan Booking Link</Button>
        </form>

        {bookingRows.length > 0 && (
          <div className="rounded-lg border border-slate-200 bg-slate-50 p-3 text-sm">
            <h4 className="font-semibold text-slate-700">Booking Link Aktif</h4>
            {bookingRows.map((r) => (
              <p key={r.id} className="mt-1 text-slate-600">{r.is_active ? '✅' : '❌'} {r.booking_url}</p>
            ))}
          </div>
        )}
      </div>

      <form className="space-y-4 rounded-lg border border-slate-200 p-4" onSubmit={onSubmitBusinessHours}>
        <h3 className="font-semibold text-slate-700">Jam Operasional</h3>
        <label className="flex items-center gap-2 text-sm text-slate-700">
          <input type="checkbox" checked={businessHoursForm.data.enabled === true} onChange={(e) => businessHoursForm.setData('enabled', e.target.checked)} />
          Aktifkan validasi jam operasional
        </label>
        <Field label="Timezone" required>
          <Input value={businessHoursForm.data.timezone} onChange={(e) => businessHoursForm.setData('timezone', e.target.value)} />
        </Field>
        <div className="grid gap-3 sm:grid-cols-2">
          <Field label="Jam Mulai"><Input type="time" value={businessHoursForm.data.start_time} onChange={(e) => businessHoursForm.setData('start_time', e.target.value)} /></Field>
          <Field label="Jam Selesai"><Input type="time" value={businessHoursForm.data.end_time} onChange={(e) => businessHoursForm.setData('end_time', e.target.value)} /></Field>
        </div>
        <Field label="Hari Operasional">
          <div className="grid grid-cols-2 gap-2 sm:grid-cols-4">
            {DAY_OPTIONS.map((day) => (
              <label key={day.key} className="flex items-center gap-2 rounded border border-slate-200 bg-white px-2 py-1 text-sm">
                <input type="checkbox" checked={(businessHoursForm.data.days ?? []).includes(day.key)} onChange={() => toggleDay(day.key)} />
                {day.label}
              </label>
            ))}
          </div>
        </Field>
        <Button type="submit" leftIcon={Save} disabled={businessHoursForm.processing}>Simpan Jam Operasional</Button>
      </form>
    </div>
  )
}
