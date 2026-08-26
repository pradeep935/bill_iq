<?php

namespace Tests\Feature;

use Tests\TestCase;

class AuthRoutesTest extends TestCase
{
    public function test_login_page_is_available_on_root_and_login_path_across_hosts(): void
    {
        $this->get('https://billing.qrsurvey.llc/')
            ->assertOk();

        $this->get('https://qrsurvey.llc/login')
            ->assertOk();
    }
}
