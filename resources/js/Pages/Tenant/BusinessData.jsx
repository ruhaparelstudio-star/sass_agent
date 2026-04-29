import React from 'react'
import { router } from '@inertiajs/react'
import TenantLayout from '../../layouts/TenantLayout'
import { Button } from '../../components/ui/button'
import { Save, Upload } from 'lucide-react'

export default function BusinessData({ data, assets }) {
  const submitCatalog = (event) => {
    event.preventDefault()
    const formData = new FormData(event.currentTarget)
    router.post('/tenant/business-data/service-catalogs', formData)
  }

  const submitPricelist = (event) => {
    event.preventDefault()
    const formData = new FormData(event.currentTarget)
    router.post('/tenant/business-data/assets/pricelist', formData)
  }

  const submitInvoice = (event) => {
    event.preventDefault()
    const formData = new FormData(event.currentTarget)
    router.post('/tenant/business-data/assets/invoice', formData)
  }

  return (
    <TenantLayout title="Business Data Management">
      <section className="grid gap-4 lg:grid-cols-2">
        <div className="rounded-xl border border-slate-200 bg-white p-4">
          <h2 className="font-semibold">Create Service Catalog</h2>
          <form className="mt-3 space-y-2" onSubmit={submitCatalog}>
            <input name="code" className="w-full rounded border p-2" placeholder="Code" required />
            <input name="name" className="w-full rounded border p-2" placeholder="Name" required />
            <textarea name="description" className="w-full rounded border p-2" placeholder="Description" />
            <Button type="submit" leftIcon={Save}>Save Catalog</Button>
          </form>
        </div>

        <div className="rounded-xl border border-slate-200 bg-white p-4">
          <h2 className="font-semibold">Upload Pricelist PDF</h2>
          <form className="mt-3 space-y-2" onSubmit={submitPricelist} encType="multipart/form-data">
            <input name="display_name" className="w-full rounded border p-2" placeholder="Display Name" />
            <input name="file" type="file" accept="application/pdf" className="w-full rounded border p-2" required />
            <Button type="submit" leftIcon={Upload}>Upload Pricelist</Button>
          </form>

          <h2 className="mt-5 font-semibold">Upload Invoice PDF</h2>
          <form className="mt-3 space-y-2" onSubmit={submitInvoice} encType="multipart/form-data">
            <input name="display_name" className="w-full rounded border p-2" placeholder="Display Name" />
            <input name="file" type="file" accept="application/pdf" className="w-full rounded border p-2" required />
            <Button type="submit" leftIcon={Upload}>Upload Invoice</Button>
          </form>
        </div>
      </section>

      <section className="mt-6 grid gap-4 lg:grid-cols-3">
        <DataBox title="Service Catalogs" rows={data?.serviceCatalogs} render={(row) => row.name} />
        <DataBox title="Products" rows={data?.products} render={(row) => row.name} />
        <DataBox title="Packages" rows={data?.packages} render={(row) => row.name} />
        <DataBox title="Prices" rows={data?.prices} render={(row) => `${row.label} (${row.currency} ${row.amount})`} />
        <DataBox title="Discounts" rows={data?.discounts} render={(row) => `${row.name} (${row.discount_type})`} />
        <DataBox title="Faqs" rows={data?.faqs} render={(row) => row.question} />
      </section>

      <section className="mt-6 rounded-xl border border-slate-200 bg-white p-4">
        <h2 className="font-semibold">Uploaded Assets</h2>
        <ul className="mt-3 space-y-2 text-sm">
          {(assets ?? []).map((row) => (
            <li key={row.id}>{row.asset_type}: {row.display_name || row.original_filename}</li>
          ))}
          {(assets ?? []).length === 0 && <li className="text-slate-500">No assets uploaded</li>}
        </ul>
      </section>
    </TenantLayout>
  )
}

function DataBox({ title, rows, render }) {
  return (
    <div className="rounded-xl border border-slate-200 bg-white p-4">
      <h3 className="font-semibold">{title}</h3>
      <ul className="mt-2 space-y-1 text-sm">
        {(rows ?? []).map((row) => (
          <li key={row.id}>{render(row)}</li>
        ))}
        {(rows ?? []).length === 0 && <li className="text-slate-500">No data</li>}
      </ul>
    </div>
  )
}
