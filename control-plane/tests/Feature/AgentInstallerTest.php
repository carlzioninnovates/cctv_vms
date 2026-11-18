<?php

namespace Tests\Feature;

use Tests\TestCase;

class AgentInstallerTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir().'/agent-release-'.uniqid();
        mkdir($this->dir);
        file_put_contents("{$this->dir}/zion_agent-9.9.9-py3-none-any.whl", 'WHEEL');
        file_put_contents("{$this->dir}/constraints.txt", "httpx==0.28.1\n");
        file_put_contents("{$this->dir}/secret.txt", 'not for download');
        file_put_contents("{$this->dir}/manifest.json", json_encode([
            'version' => '9.9.9', 'wheel' => 'zion_agent-9.9.9-py3-none-any.whl',
            'wheel_sha256' => str_repeat('a', 64), 'constraints_sha256' => str_repeat('b', 64), 'python_min' => '3.11',
            'frp' => ['version' => '0.71.1', 'sha256' => ['amd64' => str_repeat('c', 64), 'arm64' => str_repeat('d', 64)]],
        ]));
        config(['services.agent_release_dir' => $this->dir, 'app.url' => 'https://zion.example.ph']);
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob("{$this->dir}/*"));
        rmdir($this->dir);
        parent::tearDown();
    }

    public function test_installer_is_public_and_fully_filled_in(): void
    {
        $r = $this->get('/install-agent.sh')->assertOk()->assertHeader('Content-Type', 'text/x-shellscript; charset=utf-8');
        $s = $r->getContent();
        $this->assertStringStartsWith('#!/usr/bin/env bash', $s);
        $this->assertStringNotContainsString('@@', $s);
        $this->assertStringContainsString('API_URL="https://zion.example.ph"', $s);
        $this->assertStringContainsString('WHEEL_SHA256="'.str_repeat('a', 64).'"', $s);
        $this->assertStringContainsString('FRP_SHA256_arm64="'.str_repeat('d', 64).'"', $s);
        $this->assertStringContainsString('curl -fsSL https://zion.example.ph/install-agent.sh | sudo bash -s -- ABCD-EFGH', $s);
    }

    public function test_only_manifest_files_are_downloadable(): void
    {
        $this->get('/downloads/agent/zion_agent-9.9.9-py3-none-any.whl')->assertOk();
        $this->get('/downloads/agent/constraints.txt')->assertOk();
        $this->get('/downloads/agent/secret.txt')->assertNotFound();
        $this->get('/downloads/agent/manifest.json')->assertNotFound();
        $this->get('/downloads/agent/..%2F..%2F.env')->assertNotFound();
    }

    public function test_clear_error_when_release_not_built(): void
    {
        config(['services.agent_release_dir' => $this->dir.'/missing']);
        $this->get('/install-agent.sh')->assertStatus(503);
    }

    public function test_installer_script_is_valid_bash(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'inst');
        file_put_contents($file, $this->get('/install-agent.sh')->getContent());
        exec('bash -n '.escapeshellarg($file).' 2>&1', $out, $code);
        unlink($file);
        $this->assertSame(0, $code, implode("\n", $out));
    }
}
