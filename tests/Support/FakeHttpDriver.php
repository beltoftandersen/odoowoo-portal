<?php
/**
 * A do-nothing "http" driver for resources in tests.
 *
 * @package OdooWooPortal
 */

namespace OdooWooPortal\Tests\Support;

use BeltoftServices\Drivers\Driver;
use BeltoftServices\Drivers\StepResult;
use BeltoftServices\Resource;

/**
 * Same id and kind as the services plugin's http driver; every operation is done and
 * nothing touches the network.
 */
class FakeHttpDriver implements Driver {

	public function id(): string {
		return 'http';
	}

	public function label(): string {
		return 'Fake HTTP';
	}

	public function kind(): string {
		return 'http';
	}

	public function unit_label( int $used, int $max ): string {
		return sprintf( '%d of %d dashboards', $used, $max );
	}

	public function plan_fields(): array {
		return array();
	}

	public function provision( Resource $r ): StepResult {
		return StepResult::done();
	}

	public function pause( Resource $r ): StepResult {
		return StepResult::done();
	}

	public function resume( Resource $r ): StepResult {
		return StepResult::done();
	}

	public function update_plan( Resource $r ): StepResult {
		return StepResult::done();
	}

	public function delete( Resource $r ): StepResult {
		return StepResult::done();
	}

	public function status( Resource $r ): ?array {
		return null;
	}

	public function customer_actions( Resource $r ): array {
		return array();
	}

	public function act( Resource $r, string $action, array $args ): StepResult {
		return StepResult::done();
	}

	public function render_account( Resource $r ): void {}

	public function render_admin( Resource $r ): void {}
}
