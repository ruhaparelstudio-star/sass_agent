<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const TENANT_ID = 1;

    public function up(): void
    {
        $now = now();

        $packages = DB::table('packages')
            ->where('tenant_id', self::TENANT_ID)
            ->pluck('id', 'code');

        $items = [
            'PKG-0001' => [
                'Durasi 11 jam',
                '1 Photographer',
                'Flashdisk 16 GB',
                'All soft copy file photo',
                '1 Album leather cover',
                '1 Custom leather box',
                '50 foto edit untuk Instagram',
            ],
            'PKG-0002' => [
                'Durasi 11 jam',
                '1 Photographer',
                '2 Videographer',
                'Flashdisk 32 GB',
                'All soft copy file photo',
                '1 Album leather cover',
                '1 Custom leather box',
                'Video highlight (1–3 menit)',
                'Full video liputan',
                '50 foto edit untuk Instagram',
            ],
            'PKG-0003' => [
                'Durasi 11 jam',
                '1 Photographer',
                'Flashdisk 16 GB',
                'All soft copy file photo',
                '20 foto hasil editing',
                '2 cetak foto ukuran 40x60 cm + frame',
            ],
            'PKG-0004' => [
                'Durasi 11 jam',
                '1 Photographer',
                '1 Videographer',
                'Flashdisk 32 GB',
                'All soft copy file photo',
                '20 foto hasil editing',
                '10 cetak foto A5 + frame',
                '2 cetak foto ukuran 40x60 cm + frame',
                'Video (durasi 1–3 menit)',
            ],
            'PKG-0005' => [
                'Durasi 11 jam',
                '1 Photographer',
                '1 Videographer',
                'Flashdisk 32 GB',
                'All soft copy file photo',
                '20 foto hasil editing',
                '10 cetak foto A5 + frame',
                '2 cetak foto ukuran 40x60 cm + frame',
                '1 Album magazine custom cover',
                'Video (durasi 1–3 menit)',
            ],
        ];

        $aliases = [
            'PKG-0001' => ['foto wedding', 'wedding foto', 'paket foto album', 'photo album'],
            'PKG-0002' => ['foto video', 'photo video', 'wedding foto video', 'paket foto video', 'video wedding'],
            'PKG-0003' => ['prewedding foto', 'foto prewedding', 'prewedding foto only'],
            'PKG-0004' => ['prewedding foto video', 'foto video prewedding', 'prewedding photo video'],
            'PKG-0005' => ['prewedding lengkap', 'prewedding foto video album', 'prewedding premium'],
        ];

        foreach ($items as $code => $itemNames) {
            $packageId = $packages[$code] ?? null;
            if ($packageId === null) {
                continue;
            }

            foreach ($itemNames as $order => $name) {
                DB::table('package_items')->insert([
                    'tenant_id' => self::TENANT_ID,
                    'package_id' => $packageId,
                    'name' => $name,
                    'description' => null,
                    'sort_order' => $order + 1,
                    'is_active' => true,
                    'active_from' => $now,
                    'active_until' => null,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        }

        foreach ($aliases as $code => $aliasList) {
            $packageId = $packages[$code] ?? null;
            if ($packageId === null) {
                continue;
            }

            foreach ($aliasList as $order => $alias) {
                DB::table('package_aliases')->insert([
                    'tenant_id' => self::TENANT_ID,
                    'package_id' => $packageId,
                    'alias' => $alias,
                    'sort_order' => $order + 1,
                    'is_active' => true,
                    'active_from' => $now,
                    'active_until' => null,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        }
    }

    public function down(): void
    {
        $packageIds = DB::table('packages')
            ->where('tenant_id', self::TENANT_ID)
            ->pluck('id');

        DB::table('package_items')
            ->where('tenant_id', self::TENANT_ID)
            ->whereIn('package_id', $packageIds)
            ->delete();

        DB::table('package_aliases')
            ->where('tenant_id', self::TENANT_ID)
            ->whereIn('package_id', $packageIds)
            ->delete();
    }
};
