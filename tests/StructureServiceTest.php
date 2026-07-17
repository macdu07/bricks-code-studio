<?php
use BricksCodeStudio\StructureService;
use PHPUnit\Framework\TestCase;

final class StructureServiceTest extends TestCase {
	public function test_simple_static_elements_are_editable(): void {
		$service = new StructureService();
		$this->assertFalse( $service->is_protected( [ 'id' => 'abc123', 'name' => 'heading', 'settings' => [ 'text' => 'Hello' ] ] ) );
	}

	public function test_dynamic_and_complex_elements_are_protected(): void {
		$service = new StructureService();
		$this->assertTrue( $service->is_protected( [ 'id' => 'abc123', 'name' => 'heading', 'settings' => [ 'text' => '{post_title}' ] ] ) );
		$this->assertTrue( $service->is_protected( [ 'id' => 'def456', 'name' => 'form', 'settings' => [] ] ) );
		$this->assertTrue( $service->is_protected( [ 'id' => 'ghi789', 'name' => 'div', 'settings' => [ '_interactions' => [ [ 'trigger' => 'click' ] ] ] ] ) );
	}
}

