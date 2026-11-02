<?php

namespace Tests\Unit;

use App\Services\Indicadores\DashboardService;
use PHPUnit\Framework\TestCase;

class VariacaoTest extends TestCase
{
    public function test_variacao_percentual(): void
    {
        $this->assertSame(25.0, DashboardService::variacao(125, 100));
        $this->assertSame(-50.0, DashboardService::variacao(50, 100));
        $this->assertSame(200.0, DashboardService::variacao(100, -100));
    }

    public function test_variacao_sem_base_e_nula(): void
    {
        $this->assertNull(DashboardService::variacao(100, 0));
        $this->assertNull(DashboardService::variacao(null, 10));
        $this->assertNull(DashboardService::variacao(10, null));
    }

    public function test_tendencia(): void
    {
        $this->assertSame('alta', DashboardService::tendencia(2, 1));
        $this->assertSame('baixa', DashboardService::tendencia(1, 2));
        $this->assertSame('estavel', DashboardService::tendencia(1, 1));
        $this->assertNull(DashboardService::tendencia(null, 1));
    }
}
