<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $exportFile = __DIR__ . '/data_export.json';
        if (File::exists($exportFile)) {
            $data = json_decode(File::get($exportFile), true);

            if (!empty($data['users'])) {
                foreach ($data['users'] as $user) {
                    DB::table('users')->updateOrInsert(['id' => $user['id']], $user);
                }
            }

            if (!empty($data['robots'])) {
                foreach ($data['robots'] as $robot) {
                    if (isset($robot['output_formats']) && is_array($robot['output_formats'])) {
                        $robot['output_formats'] = json_encode($robot['output_formats']);
                    }
                    DB::table('robots')->updateOrInsert(['id' => $robot['id']], $robot);
                }
            }

            if (!empty($data['deals'])) {
                foreach ($data['deals'] as $deal) {
                    if (isset($deal['gallery_images']) && is_array($deal['gallery_images'])) {
                        $deal['gallery_images'] = json_encode($deal['gallery_images']);
                    }
                    if (isset($deal['product_details']) && is_array($deal['product_details'])) {
                        $deal['product_details'] = json_encode($deal['product_details']);
                    }
                    DB::table('deals')->updateOrInsert(['id' => $deal['id']], $deal);
                }
            }

            if (!empty($data['price_histories'])) {
                foreach ($data['price_histories'] as $ph) {
                    DB::table('price_histories')->updateOrInsert(['id' => $ph['id']], (array)$ph);
                }
            }

            if (!empty($data['deal_comments'])) {
                foreach ($data['deal_comments'] as $dc) {
                    DB::table('deal_comments')->updateOrInsert(['id' => $dc['id']], (array)$dc);
                }
            }

            if (!empty($data['deal_bookmarks'])) {
                foreach ($data['deal_bookmarks'] as $dbm) {
                    DB::table('deal_bookmarks')->updateOrInsert(['id' => $dbm['id']], (array)$dbm);
                }
            }

            if (!empty($data['deal_votes'])) {
                foreach ($data['deal_votes'] as $dv) {
                    DB::table('deal_votes')->updateOrInsert(['id' => $dv['id']], (array)$dv);
                }
            }
        }
    }
}
