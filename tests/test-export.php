<?php
// PHPUnit test case for the BP Export Import export functionality.

use PHPUnit\Framework\TestCase;

class BP_Export_Import_Export_Test extends TestCase
{

    /**
     * Test if the export process initializes correctly.
     */
    public function test_export_initialization()
    {
        $exporter = new BP_Export_Import_Export();
        $this->assertInstanceOf(BP_Export_Import_Export::class, $exporter);
    }

    /**
     * Test the export functionality with a mock user dataset.
     */
    public function test_export_users()
    {
        $exporter = new BP_Export_Import_Export();

        // Mock user data
        $users = [
            (object) ['ID' => 1, 'user_login' => 'user1', 'user_email' => 'user1@example.com'],
            (object) ['ID' => 2, 'user_login' => 'user2', 'user_email' => 'user2@example.com'],
        ];

        // Mock the get_users method
        $exporter->method('get_users')->willReturn($users);

        // Run the export
        ob_start();
        $exporter->export_users();
        $output = ob_get_clean();

        // Assert that the export output is not empty
        $this->assertNotEmpty($output);
    }
}
