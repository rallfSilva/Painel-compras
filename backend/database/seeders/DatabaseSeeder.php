<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([ReferenciaSeeder::class, AcessoSeeder::class, GlossarioSeeder::class]);

        // Dados demonstrativos fictícios só quando SEED_DEMO=true (padrão no .env.example de desenvolvimento)
        if (filter_var(env('SEED_DEMO', false), FILTER_VALIDATE_BOOL)) {
            $this->call(DemonstrativoSeeder::class);
        }
    }
}
