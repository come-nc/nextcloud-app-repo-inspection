#!/usr/bin/php
<?php

require_once __DIR__ . '/token.php';

// This file is generated by Composer
require_once __DIR__ . '/vendor/autoload.php';

use Cache\Adapter\Apcu\ApcuCachePool;

class Tool
{
	private Github\Client $client;
	private Github\ResultPager $paginator;

	private string $organization;

	public function __construct(string $organization, string $token) {
		$this->organization = $organization;

		$pool = new ApcuCachePool();

		$this->client = new Github\Client();
		$this->client->addCache($pool);

		$this->client->authenticate($token, '', Github\AuthMethod::ACCESS_TOKEN);

		$this->paginator = new Github\ResultPager($this->client);
	}

	public function getWorkflows(): array
	{
		$workflows = $this->paginator->fetchAll($this->client->api('repo')->contents(), 'show', [$this->organization, '.github', 'workflow-templates', 'master']);
		$workflows = array_filter(
			array_map(
				fn ($file) => $file['name'],
				$workflows
			),
			fn ($name) => str_ends_with($name, '.yml')
		);
		sort($workflows);

		return $workflows;
	}

	public function fetchRepositories($argv): array
	{
		if (isset($argv[1])) {
			array_shift($argv);
			$repositories = [];
			foreach ($argv as $reponame) {
				$repositories[] = $this->client->api('repo')->show($this->organization, $reponame);
			}
		} else {
			$repositories = $this->paginator->fetchAll($this->client->api('organization'), 'repositories', [$this->organization, 'public']);

			$repositories = array_filter(
				$repositories,
				fn($repo) =>
					$this->client->api('repo')->contents()->exists($repo['owner']['login'], $repo['name'], 'appinfo/info.xml', 'master')
					|| $this->client->api('repo')->contents()->exists($repo['owner']['login'], $repo['name'], 'appinfo/info.xml', 'main')
			);
		}

		usort(
			$repositories,
			fn ($repo1, $repo2) => $repo1['name'] <=> $repo2['name'],
		);

		return $repositories;
	}

	public function run($argv): void
	{
		$workflows = $this->getWorkflows();

		$repositories = $this->fetchRepositories($argv);

		foreach ($repositories as $repo) {
			echo "\n# {$repo['name']} (Created: {$repo['created_at']}, Last push: {$repo['pushed_at']})\n";
			try {
				$this->client->api('repo')->branches($repo['owner']['login'], $repo['name'], 'stable24');
				$appInfo = $this->getAppInfo($repo['owner']['login'], $repo['name'], 'stable24');
				echo "* stable24: version {$appInfo->version} (Nextcloud {$appInfo->dependencies->nextcloud['min-version']} to {$appInfo->dependencies->nextcloud['max-version']})\n";
			} catch (Github\Exception\RuntimeException $e) {
				if ($e->getMessage() === 'Branch not found') {
					echo "* Branch stable24 is missing\n";
				} else {
					throw $e;
				}
			}
			try {
				$lastRelease = $this->client->api('repo')->releases()->latest($repo['owner']['login'], $repo['name']);
				$appInfo = $this->getAppInfo($repo['owner']['login'], $repo['name'], $lastRelease['tag_name']);
				echo "* Last release: {$lastRelease['name']} ({$lastRelease['tag_name']}) - version {$appInfo->version} (Nextcloud {$appInfo->dependencies->nextcloud['min-version']} to {$appInfo->dependencies->nextcloud['max-version']})\n";
			} catch (Github\Exception\RuntimeException $e) {
				if ($e->getMessage() === 'Not Found') {
					echo "* No release yet\n";
				} else {
					throw $e;
				}
			}
			$lastPrerelease = $this->client->api('repo')->releases()->all($repo['owner']['login'], $repo['name'])[0];
			if ($lastPrerelease['tag_name'] !== ($lastRelease['tag_name'] ?? null)) {
				$appInfo = $this->getAppInfo($repo['owner']['login'], $repo['name'], $lastPrerelease['tag_name']);
				echo "* Last release: {$lastPrerelease['name']} ({$lastPrerelease['tag_name']}) - version {$appInfo->version} (Nextcloud {$appInfo->dependencies->nextcloud['min-version']} to {$appInfo->dependencies->nextcloud['max-version']})\n";
			}
			foreach ($workflows as $workflow) {
				if (
					!$this->client->api('repo')->contents()->exists($repo['owner']['login'], $repo['name'], '.github/workflows/'.$workflow, 'master') &&
					!$this->client->api('repo')->contents()->exists($repo['owner']['login'], $repo['name'], '.github/workflows/'.$workflow, 'main')
				) {
					echo "* $workflow is missing\n";
				}
			}
		}
	}

	public function getAppInfo (string $org, string $app, string $reference): SimpleXMLElement {
		$fileContent = $this->client->api('repo')->contents()->download($org, $app, 'appinfo/info.xml', $reference);
		return new SimpleXMLElement($fileContent);
	}
}

$tool = new Tool('nextcloud', $token);

$tool->run($argv);

/* TODO
 * Add issue and PR count?
 * Detect if a missing workflow is needed or not depending on conditions?
 * Detect or hardcode bundled app and do not search for their releases
 * Check if composer scripts are there? (lint, psalm, … workflows are using them)
 * For existing workflows detect differences with parent one
 * Check for missing composer dependencies like nextcloud/coding-standard, christophwurst/nextcloud or vimeo/psalm?
 */
