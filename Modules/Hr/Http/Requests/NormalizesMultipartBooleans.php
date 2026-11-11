<?php

namespace Modules\Hr\Http\Requests;

/** Multipart forms send booleans as "true"/"false"; turn them into real booleans before validation. */
trait NormalizesMultipartBooleans
{
    protected function prepareForValidation(): void
    {
        foreach ($this->booleanFields() as $key) {
            if ($this->has($key) && is_string($this->input($key))) {
                $value = filter_var($this->input($key), FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
                $this->merge([$key => $value ?? $this->input($key)]);
            }
        }
    }

    /** @return list<string> */
    abstract protected function booleanFields(): array;
}
