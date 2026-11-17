<?php

declare(strict_types=1);

include_once __DIR__ . '/stubs/Validator.php';

class ValidationTest extends TestCaseSymconValidation
{
    public function testValidateLibrary(): void
    {
        $this->validateLibrary(__DIR__ . '/..');
    }

    public function testValidateRoborockCloud(): void
    {
        $this->validateModule(__DIR__ . '/../RoborockCloud');
    }

    public function testValidateRoborockConfigurator(): void
    {
        $this->validateModule(__DIR__ . '/../RoborockConfigurator');
    }

    public function testValidateRoborockVacuum(): void
    {
        $this->validateModule(__DIR__ . '/../RoborockVacuum');
    }
}
