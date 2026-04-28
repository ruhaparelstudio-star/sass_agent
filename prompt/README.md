# Claude Phase Files — Kanban Vertical Lane

Struktur ini mengubah phase linear menjadi Kanban lane agar development bisa paralel dan tidak saling blocking.

## Cara Pakai

1. Jalankan `00-foundation/F0-project-foundation.md` terlebih dahulu.
2. Setelah F0 selesai, lane lain bisa dikerjakan paralel.
3. Setiap card adalah unit kerja individual.
4. Claude Code hanya boleh membaca file phase yang sedang dikerjakan.
5. Jangan gabungkan beberapa card dalam satu sesi kecuali diminta.

## Lane

- `00-foundation`
- `01-infra`
- `02-whatsapp`
- `03-conversation`
- `04-data-knowledge`
- `05-ai-layer`
- `06-core-engine`
- `07-validation-safety`
- `08-action`
- `09-admin-ui`
- `10-integration`
- `11-memory`
- `12-analytics`
- `13-hardening`

## Kanban Status

Gunakan status berikut:

- Backlog
- Ready
- In Progress
- Review
- Test
- Done
- Blocked

## Rule Utama

Selesaikan setiap card sampai test passing dan Phase Report lengkap sebelum pindah ke card berikutnya.
