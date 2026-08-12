<?php

namespace Tests\Feature;

use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Pagination\Paginator;
use Tests\TestCase;

class SmartDataPaginationTest extends TestCase
{
    public function test_full_pagination_uses_the_smart_data_design_and_preserves_filters(): void
    {
        $paginator = new LengthAwarePaginator(
            range(7, 12),
            22,
            6,
            2,
            [
                'path' => 'http://localhost/backoffice/courses',
                'pageName' => 'activos_page',
                'query' => [
                    'tab' => 'activos',
                    'search' => 'datos',
                ],
            ],
        );

        $html = $paginator->links()->toHtml();

        $this->assertStringContainsString('smart-pagination', $html);
        $this->assertStringContainsString('Mostrando', $html);
        $this->assertStringContainsString('P&aacute;gina 2 de 4', htmlentities($html));
        $this->assertStringContainsString('aria-current="page"', $html);
        $this->assertStringContainsString('activos_page=3', $html);
        $this->assertStringContainsString('tab=activos', $html);
        $this->assertStringContainsString('search=datos', $html);
        $this->assertStringNotContainsString('Showing', $html);
        $this->assertStringNotContainsString('results', $html);
    }

    public function test_full_pagination_is_not_rendered_for_a_single_page(): void
    {
        $paginator = new LengthAwarePaginator(
            range(1, 4),
            4,
            6,
            1,
            ['path' => 'http://localhost/backoffice/courses'],
        );

        $this->assertSame('', trim($paginator->links()->toHtml()));
    }

    public function test_simple_pagination_uses_spanish_navigation(): void
    {
        $paginator = new Paginator(
            [7, 8, 9],
            2,
            2,
            [
                'path' => 'http://localhost/listado',
                'pageName' => 'page',
            ],
        );

        $html = $paginator->links()->toHtml();

        $this->assertStringContainsString('Anterior', $html);
        $this->assertStringContainsString('Siguiente', $html);
        $this->assertStringContainsString('P&aacute;gina 2', htmlentities($html));
        $this->assertStringContainsString('rel="prev"', $html);
        $this->assertStringContainsString('rel="next"', $html);
    }
}
