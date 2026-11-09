<?php

namespace Tests\Feature;

use Tests\TestCase;

class LandingPageTest extends TestCase
{
    public function test_landing_page_renders_in_russian_by_default(): void
    {
        $this->get('/')
            ->assertOk()
            ->assertSee('THE NON')
            ->assertSee('Вкусные хлеба для вашего дома');
    }

    public function test_visitor_can_switch_the_landing_page_to_uzbek(): void
    {
        $this->get('/lang/uz')->assertRedirect();

        $this->get('/')
            ->assertOk()
            ->assertSee('Uyingiz uchun mazali nonlar');
    }

    public function test_unsupported_locale_is_rejected(): void
    {
        $this->get('/lang/en')->assertNotFound();
    }
}
