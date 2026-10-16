<?php

declare(strict_types=1);

namespace Folklore\Image\Testing\Contracts;

use Folklore\Image\Contracts\Operation;
use Folklore\Image\Exceptions\InvalidImageSpec;
use Intervention\Image\Interfaces\ImageInterface;
use PHPUnit\Framework\TestCase;

/**
 * The behaviour every Operation has, for its own test case.
 *
 * @phpstan-require-extends TestCase
 */
trait OperationContractTests
{
    abstract protected function operation(): Operation;

    /**
     * An image to apply the operation to, on the driver under test.
     */
    abstract protected function image(): ImageInterface;

    /**
     * Valid arguments, as a spec could give them.
     *
     * @return list<list<string>>
     */
    abstract protected function validArguments(): array;

    /**
     * Arguments the operation refuses.
     *
     * @return list<list<string>>
     */
    abstract protected function invalidArguments(): array;

    public function test_canonical_arguments_stay_canonical(): void
    {
        foreach ($this->validArguments() as $arguments) {
            $canonical = $this->operation()->arguments($arguments);

            $this->assertSame($canonical, $this->operation()->arguments($canonical));
            foreach ($canonical as $argument) {
                $this->assertMatchesRegularExpression('/^[a-z0-9]{1,32}$/', $argument);
            }
        }
    }

    public function test_invalid_arguments_are_refused(): void
    {
        foreach ($this->invalidArguments() as $arguments) {
            try {
                $this->operation()->arguments($arguments);
                $this->fail('The arguments ['.implode(', ', $arguments).'] were accepted.');
            } catch (InvalidImageSpec) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_the_operation_applies_to_an_image(): void
    {
        foreach ($this->validArguments() as $arguments) {
            $image = $this->operation()->apply($this->image(), $this->operation()->arguments($arguments));

            $this->assertGreaterThan(0, $image->width());
        }
    }
}
