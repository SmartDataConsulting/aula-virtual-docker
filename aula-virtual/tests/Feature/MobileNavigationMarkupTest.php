<?php

namespace Tests\Feature;

use Tests\TestCase;

class MobileNavigationMarkupTest extends TestCase
{
    public function test_mobile_navigation_exposes_accessible_open_and_close_controls(): void
    {
        $response = $this->get('/login');

        $response->assertOk();

        $layout = file_get_contents(resource_path('views/layouts/main.blade.php'));

        $this->assertStringContainsString('id="mobileBackdrop"', $layout);
        $this->assertStringContainsString('id="mobileClose"', $layout);
        $this->assertStringContainsString('aria-controls="mobileNav"', $layout);
        $this->assertStringContainsString('aria-expanded="false"', $layout);
        $this->assertMatchesRegularExpression('/id="mobileNav"[^>]+aria-hidden="true"[^>]+inert/', $layout);
        $this->assertMatchesRegularExpression('/id="userDropdown"[^>]+aria-hidden="true"[^>]+inert/', $layout);

        $script = file_get_contents(resource_path('js/app.js'));

        $this->assertStringContainsString('setInteractiveVisibility', $script);
        $this->assertStringContainsString("panel.setAttribute('inert', '')", $script);
        $this->assertStringContainsString('const trapFocus', $script);
        $this->assertStringContainsString("mobileClose?.addEventListener('click'", $script);
        $this->assertStringContainsString("mobileBackdrop?.addEventListener('click'", $script);
        $this->assertStringContainsString("if (e.key !== 'Escape')", $script);
        $this->assertStringContainsString("document.body.classList.toggle('mobile-menu-open'", $script);
    }

    public function test_course_community_drawer_is_inert_while_closed(): void
    {
        $layout = file_get_contents(
            resource_path('views/backoffice/courses/partials/layout.blade.php')
        );
        $script = file_get_contents(resource_path('js/course-workspace.js'));

        $this->assertMatchesRegularExpression(
            '/id="courseCommunityDrawer"[\s\S]+?aria-hidden="true"[\s\S]+?inert>/',
            $layout
        );
        $this->assertStringContainsString('communityDrawer.inert = false', $script);
        $this->assertStringContainsString("communityDrawer.setAttribute('inert', '')", $script);
    }
}
