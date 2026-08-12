<?php

namespace Tests\Feature\Backoffice;

use Tests\TestCase;

class SearchToolbarConsistencyTest extends TestCase
{
    public function test_courses_and_certificates_use_the_shared_search_toolbar(): void
    {
        foreach ([
            'backoffice/courses/index.blade.php',
            'backoffice/certificates/index.blade.php',
        ] as $relativePath) {
            $view = file_get_contents(resource_path('views/'.$relativePath));

            $this->assertStringContainsString('qualification-search-toolbar', $view, $relativePath);
            $this->assertStringContainsString('qualification-search-box', $view, $relativePath);
            $this->assertStringContainsString('qualification-search-help', $view, $relativePath);
            $this->assertStringContainsString('type="search"', $view, $relativePath);
        }
    }

    public function test_courses_and_certificates_expose_live_filter_targets(): void
    {
        $courses = file_get_contents(resource_path('views/backoffice/courses/index.blade.php'));
        $certificates = file_get_contents(resource_path('views/backoffice/certificates/index.blade.php'));

        $this->assertStringContainsString('js-live-course-card', $courses);
        $this->assertStringContainsString('data-course-no-results', $courses);
        $this->assertStringContainsString('js-live-certificate-card', $certificates);
        $this->assertStringContainsString('certificateNoResults', $certificates);

        $coursesScript = file_get_contents(resource_path('js/backoffice-courses-index.js'));
        $certificatesScript = file_get_contents(resource_path('js/backoffice-certificates-index.js'));

        $this->assertStringContainsString("search.addEventListener('input', updateLiveResults)", $coursesScript);
        $this->assertStringContainsString('updateLiveResults();', $certificatesScript);
    }
}
