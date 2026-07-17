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

	public function test_global_and_local_classes_are_serialized_as_html_names(): void {
		$service = new StructureService();
		$method = new ReflectionMethod( StructureService::class, 'class_names_for_settings' );
		$method->setAccessible( true );
		$names = $method->invoke( $service, [ '_cssGlobalClasses' => [ 'raaekj' ], '_cssClasses' => 'utility extra' ], [ 'raaekj' => 'my-container' ] );
		$this->assertSame( [ 'my-container', 'utility', 'extra' ], $names );
	}

	public function test_html_class_names_are_mapped_back_to_bricks_global_ids(): void {
		$service = new StructureService();
		$method = new ReflectionMethod( StructureService::class, 'apply_class_tokens' );
		$method->setAccessible( true );
		$settings = $method->invoke( $service, [], [ 'my-container', 'u-grid' ], [ 'my-container' => 'raaekj' ] );
		$this->assertSame( [ 'raaekj' ], $settings['_cssGlobalClasses'] );
		$this->assertSame( 'u-grid', $settings['_cssClasses'] );
	}
}
