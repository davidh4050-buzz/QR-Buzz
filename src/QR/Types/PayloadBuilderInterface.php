<?php
namespace QRBuzz\QR\Types;

interface PayloadBuilderInterface {

    public function type(): string;

    public function build(array $data): string;
}
