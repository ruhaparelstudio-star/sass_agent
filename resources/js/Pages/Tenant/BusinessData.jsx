import React, { useMemo } from 'react'
import { router, useForm, usePage } from '@inertiajs/react'
import TenantLayout from '../../layouts/TenantLayout'
import { Button } from '../../components/ui/button'
import { Input } from '../../components/ui/input'
import { Select } from '../../components/ui/select'
import { Badge } from '../../components/ui/badge'
import { ArrowRight, CheckCircle2, Lock, Save, Upload } from 'lucide-react'

const STEP_ORDER = ['catalog', 'product', 'package', 'pricing', 'faq', 'assets']

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
  const prices = data?.prices ?? []
  const discounts = data?.discounts ?? []
  const faqs = data?.faqs ?? []
  const uploadedAssets = assets ?? []

  const gate = {
    catalog: true,
    product: catalogs.length > 0,
    package: products.length > 0,
    pricing: packages.length > 0,
    faq: true,
    assets: true,
  }

  const progress = {
    catalog: catalogs.length > 0,
    product: products.length > 0,
    package: packages.length > 0,
    pricing: prices.length > 0 || discounts.length > 0,
    faq: faqs.length > 0,
    assets: uploadedAssets.length > 0,
  }

  const stepMeta = {
    catalog: { title: 'Langkah 1: Katalog Layanan', description: 'Mulai dengan kategori layanan utama bisnis.', emptyHint: 'Buat minimal 1 katalog layanan dulu sebelum menambahkan produk.', cta: 'Lanjut ke Produk', next: 'product' },
    product: { title: 'Langkah 2: Produk', description: 'Tambahkan produk turunan dari katalog layanan.', emptyHint: 'Pilih katalog layanan induk saat membuat produk.', cta: 'Lanjut ke Paket', next: 'package' },
    package: { title: 'Langkah 3: Paket', description: 'Buat paket penawaran untuk kebutuhan harga dan diskon.', emptyHint: 'Produk harus tersedia sebelum membuat paket.', cta: 'Lanjut ke Harga & Diskon', next: 'pricing' },
    pricing: { title: 'Langkah 4: Harga & Diskon', description: 'Isi harga dan promo agar jawaban AI tetap grounded.', emptyHint: 'Buat minimal 1 harga atau diskon untuk paket aktif.', cta: 'Lanjut ke FAQ', next: 'faq' },
    faq: { title: 'Langkah 5: FAQ', description: 'Siapkan jawaban standar untuk pertanyaan berulang.', emptyHint: 'Tambahkan FAQ untuk memperkuat konsistensi jawaban AI.', cta: 'Lanjut ke Aset', next: 'assets' },
    assets: { title: 'Langkah 6: Aset', description: 'Unggah dokumen pendukung agar flow siap end-to-end.', emptyHint: 'Unggah PDF daftar harga/invoice sebagai dokumen resmi tenant.', cta: null, next: null },
  }

  const goToStep = (stepKey) => {
    if (!STEP_ORDER.includes(stepKey) || !gate[stepKey]) return
    router.get(`/tenant/business-data?step=${stepKey}`, {}, { preserveState: true, preserveScroll: true, replace: true })
  }

  const catalogForm = useForm({ name: '', description: '', sort_order: '' })
  const productForm = useForm({ service_catalog_id: catalogs[0]?.id ? String(catalogs[0].id) : '', name: '', description: '', sort_order: '' })
  const packageForm = useForm({ product_id: products[0]?.id ? String(products[0].id) : '', name: '', description: '', sort_order: '' })
  const priceForm = useForm({ package_id: packages[0]?.id ? String(packages[0].id) : '', label: '', currency: 'IDR', amount: '', sort_order: '' })
  const discountForm = useForm({ package_id: packages[0]?.id ? String(packages[0].id) : '', name: '', discount_type: discountTypes[0] ?? 'percentage', value: '', sort_order: '' })
  const faqForm = useForm({ question: '', answer: '', sort_order: '' })
  const pricelistForm = useForm({ display_name: '', file: null })
  const invoiceForm = useForm({ display_name: '', file: null })

  const resolvedStep = gate[currentStep] ? currentStep : STEP_ORDER.find((step) => gate[step]) ?? 'catalog'

  const submitCatalog = (event) => { event.preventDefault(); catalogForm.post('/tenant/business-data/service-catalogs', { preserveScroll: true, onSuccess: () => catalogForm.reset() }) }
  const submitProduct = (event) => { event.preventDefault(); productForm.post('/tenant/business-data/products', { preserveScroll: true, onSuccess: () => productForm.reset('name', 'description', 'sort_order') }) }
  const submitPackage = (event) => { event.preventDefault(); packageForm.post('/tenant/business-data/packages', { preserveScroll: true, onSuccess: () => packageForm.reset('name', 'description', 'sort_order') }) }
  const submitPrice = (event) => { event.preventDefault(); priceForm.post('/tenant/business-data/prices', { preserveScroll: true, onSuccess: () => priceForm.reset('label', 'amount', 'sort_order') }) }
  const submitDiscount = (event) => { event.preventDefault(); discountForm.post('/tenant/business-data/discounts', { preserveScroll: true, onSuccess: () => discountForm.reset('name', 'value', 'sort_order') }) }
  const submitFaq = (event) => { event.preventDefault(); faqForm.post('/tenant/business-data/faqs', { preserveScroll: true, onSuccess: () => faqForm.reset() }) }
  const submitPricelist = (event) => { event.preventDefault(); pricelistForm.post('/tenant/business-data/assets/pricelist', { preserveScroll: true, forceFormData: true, onSuccess: () => pricelistForm.reset() }) }
  const submitInvoice = (event) => { event.preventDefault(); invoiceForm.post('/tenant/business-data/assets/invoice', { preserveScroll: true, forceFormData: true, onSuccess: () => invoiceForm.reset() }) }

  const steps = STEP_ORDER.map((key, index) => ({ key, index: index + 1, label: stepMeta[key].title.replace(/^Langkah\s\d+:\s/, ''), unlocked: gate[key], done: progress[key], active: resolvedStep === key }))
  const activeStepMeta = stepMeta[resolvedStep]

  return (
    <TenantLayout title="Pengelolaan Data Bisnis">
      <section className="rounded-2xl border border-slate-200 bg-white p-4">
        <p className="text-sm text-slate-600">Ikuti langkah berurutan agar data bisnis siap dipakai AI sales engine secara end-to-end.</p>
        <StepNav steps={steps} onSelect={goToStep} />
      </section>

      <section className="mt-4">
        {flash.success ? <div className="mb-3 rounded-lg border border-emerald-200 bg-emerald-50 px-3 py-2 text-sm text-emerald-800">{flash.success}</div> : null}
        {flash.error ? <div className="mb-3 rounded-lg border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-700">{flash.error}</div> : null}

        <StepCard title={activeStepMeta.title} description={activeStepMeta.description} isDone={progress[resolvedStep]} emptyHint={activeStepMeta.emptyHint} hasData={progress[resolvedStep]}>
          {resolvedStep === 'catalog' && <CatalogStep form={catalogForm} rows={catalogs} onSubmit={submitCatalog} />}
          {resolvedStep === 'product' && <ProductStep form={productForm} catalogs={catalogs} rows={products} onSubmit={submitProduct} disabled={!gate.product} />}
          {resolvedStep === 'package' && <PackageStep form={packageForm} products={products} rows={packages} onSubmit={submitPackage} disabled={!gate.package} />}
          {resolvedStep === 'pricing' && <PricingStep priceForm={priceForm} discountForm={discountForm} packages={packages} prices={prices} discounts={discounts} discountTypes={discountTypes} onSubmitPrice={submitPrice} onSubmitDiscount={submitDiscount} disabled={!gate.pricing} />}
          {resolvedStep === 'faq' && <FaqStep form={faqForm} rows={faqs} onSubmit={submitFaq} />}
          {resolvedStep === 'assets' && <AssetsStep pricelistForm={pricelistForm} invoiceForm={invoiceForm} rows={uploadedAssets} onSubmitPricelist={submitPricelist} onSubmitInvoice={submitInvoice} />}
        </StepCard>

        {activeStepMeta.next && gate[activeStepMeta.next] && progress[resolvedStep] ? (
          <div className="mt-3 flex justify-end"><Button type="button" rightIcon={ArrowRight} onClick={() => goToStep(activeStepMeta.next)}>{activeStepMeta.cta}</Button></div>
        ) : null}
      </section>
    </TenantLayout>
  )
}

function StepNav({ steps, onSelect }) {
  return (
    <ol className="mt-4 grid gap-2 md:grid-cols-2 xl:grid-cols-3">
      {steps.map((step) => (
        <li key={step.key}>
          <button type="button" onClick={() => onSelect(step.key)} disabled={!step.unlocked} className={`flex w-full items-center justify-between rounded-lg border px-3 py-2 text-left transition ${step.active ? 'border-emerald-400 bg-emerald-50' : 'border-slate-200 bg-white'} ${!step.unlocked ? 'cursor-not-allowed opacity-60' : 'hover:border-emerald-300'}`}>
            <span><span className="mr-2 text-xs font-semibold uppercase text-slate-500">Langkah {step.index}</span><span className="text-sm font-medium text-slate-900">{step.label}</span></span>
            {step.done ? <Badge tone="success" className="inline-flex items-center gap-1"><CheckCircle2 className="h-3 w-3" />Selesai</Badge> : step.unlocked ? <Badge>Sedang Proses</Badge> : <Badge tone="danger" className="inline-flex items-center gap-1"><Lock className="h-3 w-3" />Terkunci</Badge>}
          </button>
        </li>
      ))}
    </ol>
  )
}

function StepCard({ title, description, isDone, emptyHint, hasData, children }) {
  return (
    <div className="rounded-2xl border border-slate-200 bg-white p-4">
      <div className="flex flex-wrap items-center justify-between gap-2"><h2 className="text-lg font-semibold">{title}</h2><Badge tone={isDone ? 'success' : 'neutral'}>{isDone ? 'Selesai' : 'Sedang Proses'}</Badge></div>
      <p className="mt-1 text-sm text-slate-600">{description}</p>
      {!hasData ? <p className="mt-2 rounded-md bg-amber-50 px-3 py-2 text-sm text-amber-700">{emptyHint}</p> : null}
      <div className="mt-4">{children}</div>
    </div>
  )
}

function Field({ label, required = false, children }) { return <label className="block space-y-1"><span className="text-sm font-medium text-slate-700">{label} {required ? <span className="text-red-600">*</span> : null}</span>{children}</label> }
function FieldError({ message }) { return message ? <p className="text-xs text-red-600">{message}</p> : null }
function InlineList({ title, rows, render, emptyText }) { return <div className="rounded-lg border border-slate-200 p-3"><h3 className="font-semibold">{title}</h3><ul className="mt-2 space-y-1 text-sm">{(rows ?? []).map((row) => <li key={row.id} className="rounded border border-slate-100 bg-slate-50 px-2 py-1">{render(row)}</li>)}{(rows ?? []).length === 0 ? <li className="text-slate-500">{emptyText}</li> : null}</ul></div> }

function CatalogStep({ form, rows, onSubmit }) {
  return <div className="grid gap-4 lg:grid-cols-2"><form className="space-y-3" onSubmit={onSubmit}><Field label="Kode Katalog (Otomatis)"><Input value="Akan dibuat otomatis (format: CAT-0001)" disabled /><p className="text-xs text-slate-500">Kode dibuat sistem saat simpan dengan prefix jelas: <span className="font-semibold">CAT-</span>.</p><FieldError message={form.errors.code} /></Field><Field label="Nama Katalog" required><Input value={form.data.name} onChange={(e) => form.setData('name', e.target.value)} placeholder="Layanan Wedding" /><FieldError message={form.errors.name} /></Field><Field label="Deskripsi"><textarea className="w-full rounded-md border border-slate-300 px-3 py-2 text-sm" value={form.data.description} onChange={(e) => form.setData('description', e.target.value)} rows={3} /><FieldError message={form.errors.description} /></Field><Field label="Urutan"><Input type="number" min="0" value={form.data.sort_order} onChange={(e) => form.setData('sort_order', e.target.value)} /><FieldError message={form.errors.sort_order} /></Field><Button type="submit" leftIcon={Save} disabled={form.processing}>Simpan Katalog</Button></form><InlineList title="Daftar Katalog" rows={rows} emptyText="Belum ada katalog layanan." render={(row) => `${row.name} (${row.code})`} /></div>
}

function ProductStep({ form, catalogs, rows, onSubmit, disabled }) {
  return <div className="grid gap-4 lg:grid-cols-2"><form className="space-y-3" onSubmit={onSubmit}><Field label="Katalog Layanan" required><Select value={form.data.service_catalog_id} onChange={(e) => form.setData('service_catalog_id', e.target.value)} disabled={disabled}>{!catalogs.length ? <option value="">Belum ada katalog</option> : null}{catalogs.map((row) => <option key={row.id} value={row.id}>{row.name}</option>)}</Select><FieldError message={form.errors.service_catalog_id} /></Field><Field label="Kode Produk (Otomatis)"><Input value="Akan dibuat otomatis (format: PRD-0001)" disabled /><p className="text-xs text-slate-500">Kode dibuat sistem saat simpan dengan prefix: <span className="font-semibold">PRD-</span>.</p><FieldError message={form.errors.code} /></Field><Field label="Nama Produk" required><Input value={form.data.name} onChange={(e) => form.setData('name', e.target.value)} disabled={disabled} /><FieldError message={form.errors.name} /></Field><Field label="Deskripsi"><textarea className="w-full rounded-md border border-slate-300 px-3 py-2 text-sm" value={form.data.description} onChange={(e) => form.setData('description', e.target.value)} rows={3} disabled={disabled} /><FieldError message={form.errors.description} /></Field><Field label="Urutan"><Input type="number" min="0" value={form.data.sort_order} onChange={(e) => form.setData('sort_order', e.target.value)} disabled={disabled} /><FieldError message={form.errors.sort_order} /></Field><Button type="submit" leftIcon={Save} disabled={disabled || form.processing}>Simpan Produk</Button></form><InlineList title="Daftar Produk" rows={rows} emptyText="Belum ada produk." render={(row) => `${row.name} (${row.code})`} /></div>
}

function PackageStep({ form, products, rows, onSubmit, disabled }) {
  return <div className="grid gap-4 lg:grid-cols-2"><form className="space-y-3" onSubmit={onSubmit}><Field label="Produk" required><Select value={form.data.product_id} onChange={(e) => form.setData('product_id', e.target.value)} disabled={disabled}>{!products.length ? <option value="">Belum ada produk</option> : null}{products.map((row) => <option key={row.id} value={row.id}>{row.name}</option>)}</Select><FieldError message={form.errors.product_id} /></Field><Field label="Kode Paket (Otomatis)"><Input value="Akan dibuat otomatis (format: PKG-0001)" disabled /><p className="text-xs text-slate-500">Kode dibuat sistem saat simpan dengan prefix: <span className="font-semibold">PKG-</span>.</p><FieldError message={form.errors.code} /></Field><Field label="Nama Paket" required><Input value={form.data.name} onChange={(e) => form.setData('name', e.target.value)} disabled={disabled} /><FieldError message={form.errors.name} /></Field><Field label="Deskripsi"><textarea className="w-full rounded-md border border-slate-300 px-3 py-2 text-sm" value={form.data.description} onChange={(e) => form.setData('description', e.target.value)} rows={3} disabled={disabled} /><FieldError message={form.errors.description} /></Field><Field label="Urutan"><Input type="number" min="0" value={form.data.sort_order} onChange={(e) => form.setData('sort_order', e.target.value)} disabled={disabled} /><FieldError message={form.errors.sort_order} /></Field><Button type="submit" leftIcon={Save} disabled={disabled || form.processing}>Simpan Paket</Button></form><InlineList title="Daftar Paket" rows={rows} emptyText="Belum ada paket." render={(row) => `${row.name} (${row.code})`} /></div>
}

function PricingStep({ priceForm, discountForm, packages, prices, discounts, discountTypes, onSubmitPrice, onSubmitDiscount, disabled }) {
  return <div className="grid gap-4 lg:grid-cols-2"><div className="space-y-4"><form className="space-y-3 rounded-lg border border-slate-200 p-3" onSubmit={onSubmitPrice}><h3 className="font-semibold">Buat Harga</h3><Field label="Paket" required><Select value={priceForm.data.package_id} onChange={(e) => priceForm.setData('package_id', e.target.value)} disabled={disabled}>{!packages.length ? <option value="">Belum ada paket</option> : null}{packages.map((row) => <option key={row.id} value={row.id}>{row.name}</option>)}</Select><FieldError message={priceForm.errors.package_id} /></Field><Field label="Label Harga" required><Input value={priceForm.data.label} onChange={(e) => priceForm.setData('label', e.target.value)} disabled={disabled} /><FieldError message={priceForm.errors.label} /></Field><div className="grid gap-3 sm:grid-cols-2"><Field label="Mata Uang" required><Input value={priceForm.data.currency} onChange={(e) => priceForm.setData('currency', e.target.value)} disabled={disabled} /><FieldError message={priceForm.errors.currency} /></Field><Field label="Nominal" required><Input type="number" min="0" value={priceForm.data.amount} onChange={(e) => priceForm.setData('amount', e.target.value)} disabled={disabled} /><FieldError message={priceForm.errors.amount} /></Field></div><Field label="Urutan"><Input type="number" min="0" value={priceForm.data.sort_order} onChange={(e) => priceForm.setData('sort_order', e.target.value)} disabled={disabled} /><FieldError message={priceForm.errors.sort_order} /></Field><Button type="submit" leftIcon={Save} disabled={disabled || priceForm.processing}>Simpan Harga</Button></form><form className="space-y-3 rounded-lg border border-slate-200 p-3" onSubmit={onSubmitDiscount}><h3 className="font-semibold">Buat Diskon</h3><Field label="Paket" required><Select value={discountForm.data.package_id} onChange={(e) => discountForm.setData('package_id', e.target.value)} disabled={disabled}>{!packages.length ? <option value="">Belum ada paket</option> : null}{packages.map((row) => <option key={row.id} value={row.id}>{row.name}</option>)}</Select><FieldError message={discountForm.errors.package_id} /></Field><Field label="Nama Diskon" required><Input value={discountForm.data.name} onChange={(e) => discountForm.setData('name', e.target.value)} disabled={disabled} /><FieldError message={discountForm.errors.name} /></Field><div className="grid gap-3 sm:grid-cols-2"><Field label="Jenis Diskon" required><Select value={discountForm.data.discount_type} onChange={(e) => discountForm.setData('discount_type', e.target.value)} disabled={disabled}>{discountTypes.map((type) => <option key={type} value={type}>{type}</option>)}</Select><FieldError message={discountForm.errors.discount_type} /></Field><Field label="Nilai" required><Input type="number" min="0" value={discountForm.data.value} onChange={(e) => discountForm.setData('value', e.target.value)} disabled={disabled} /><FieldError message={discountForm.errors.value} /></Field></div><Field label="Urutan"><Input type="number" min="0" value={discountForm.data.sort_order} onChange={(e) => discountForm.setData('sort_order', e.target.value)} disabled={disabled} /><FieldError message={discountForm.errors.sort_order} /></Field><Button type="submit" leftIcon={Save} disabled={disabled || discountForm.processing}>Simpan Diskon</Button></form></div><div className="space-y-4"><InlineList title="Daftar Harga" rows={prices} emptyText="Belum ada harga." render={(row) => `${row.label} (${row.currency} ${row.amount})`} /><InlineList title="Daftar Diskon" rows={discounts} emptyText="Belum ada diskon." render={(row) => `${row.name} (${row.discount_type}: ${row.value})`} /></div></div>
}

function FaqStep({ form, rows, onSubmit }) {
  return <div className="grid gap-4 lg:grid-cols-2"><form className="space-y-3" onSubmit={onSubmit}><Field label="Pertanyaan" required><Input value={form.data.question} onChange={(e) => form.setData('question', e.target.value)} /><FieldError message={form.errors.question} /></Field><Field label="Jawaban" required><textarea className="w-full rounded-md border border-slate-300 px-3 py-2 text-sm" value={form.data.answer} onChange={(e) => form.setData('answer', e.target.value)} rows={4} /><FieldError message={form.errors.answer} /></Field><Field label="Urutan"><Input type="number" min="0" value={form.data.sort_order} onChange={(e) => form.setData('sort_order', e.target.value)} /><FieldError message={form.errors.sort_order} /></Field><Button type="submit" leftIcon={Save} disabled={form.processing}>Simpan FAQ</Button></form><InlineList title="Daftar FAQ" rows={rows} emptyText="Belum ada FAQ." render={(row) => row.question} /></div>
}

function AssetsStep({ pricelistForm, invoiceForm, rows, onSubmitPricelist, onSubmitInvoice }) {
  return <div className="grid gap-4 lg:grid-cols-2"><div className="space-y-4"><form className="space-y-3 rounded-lg border border-slate-200 p-3" onSubmit={onSubmitPricelist}><h3 className="font-semibold">Unggah PDF Daftar Harga</h3><Field label="Nama Tampilan"><Input value={pricelistForm.data.display_name} onChange={(e) => pricelistForm.setData('display_name', e.target.value)} /><FieldError message={pricelistForm.errors.display_name} /></Field><Field label="Berkas PDF" required><Input type="file" accept="application/pdf" onChange={(e) => pricelistForm.setData('file', e.target.files?.[0] ?? null)} /><FieldError message={pricelistForm.errors.file} /></Field><Button type="submit" leftIcon={Upload} disabled={pricelistForm.processing}>Unggah Daftar Harga</Button></form><form className="space-y-3 rounded-lg border border-slate-200 p-3" onSubmit={onSubmitInvoice}><h3 className="font-semibold">Unggah PDF Invoice</h3><Field label="Nama Tampilan"><Input value={invoiceForm.data.display_name} onChange={(e) => invoiceForm.setData('display_name', e.target.value)} /><FieldError message={invoiceForm.errors.display_name} /></Field><Field label="Berkas PDF" required><Input type="file" accept="application/pdf" onChange={(e) => invoiceForm.setData('file', e.target.files?.[0] ?? null)} /><FieldError message={invoiceForm.errors.file} /></Field><Button type="submit" leftIcon={Upload} disabled={invoiceForm.processing}>Unggah Invoice</Button></form></div><InlineList title="Aset Terunggah" rows={rows} emptyText="Belum ada aset terunggah." render={(row) => `${row.asset_type}: ${row.display_name || row.original_filename}`} /></div>
}
