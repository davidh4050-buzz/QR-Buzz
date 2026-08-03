<?php
namespace QRBuzz\SmartDestinations;

use QRBuzz\Database\DestinationRuleRepository;
use QRBuzz\Database\QRRepository;
use QRBuzz\Models\DestinationRule;
use QRBuzz\Models\QRCode;

class DestinationRuleService {
    private DestinationRuleRepository $rules;
    private QRRepository $qrs;
    private RuleValidationService $validator;
    public function __construct(?DestinationRuleRepository $rules = null, ?QRRepository $qrs = null, ?RuleValidationService $validator = null) { $this->rules = $rules ?: new DestinationRuleRepository(); $this->qrs = $qrs ?: new QRRepository(); $this->validator = $validator ?: new RuleValidationService(); }
    public function qr(int $qrId): ?QRCode { $qr = $this->qrs->find($qrId); return $qr && $qr->isTrackable() ? $qr : null; }
    public function list(int $qrId): array { return $this->qr($qrId) ? $this->rules->forQrCode($qrId) : []; }
    public function get(int $qrId, int $ruleId): ?DestinationRule { return $this->qr($qrId) ? $this->rules->findForQrCode($ruleId, $qrId) : null; }
    public function save(int $qrId, array $input, int $userId, int $ruleId = 0): array {
        if (!$this->qr($qrId)) { return ['id' => 0, 'errors' => ['qr' => 'Dynamic QR code not found.']]; }
        if ($ruleId > 0 && !$this->get($qrId, $ruleId)) { return ['id' => 0, 'errors' => ['rule' => 'Smart Destination rule not found.']]; }
        $errors = $this->validator->validate($input); if ($errors) { return ['id' => 0, 'errors' => $errors]; }
        $data = $this->validator->normalize($input);
        if ($ruleId > 0) { return ['id' => $this->rules->update($ruleId, $data, $userId) ? $ruleId : 0, 'errors' => []]; }
        return ['id' => $this->rules->create($qrId, $data, $userId), 'errors' => []];
    }
    public function delete(int $qrId, int $ruleId, int $userId): bool { return $this->get($qrId, $ruleId) ? $this->rules->delete($ruleId, $userId) : false; }
    public function toggle(int $qrId, int $ruleId, int $userId): bool { $rule = $this->get($qrId, $ruleId); return $rule ? $this->rules->setStatus($ruleId, $rule->isActive() ? 'inactive' : 'active', $userId) : false; }
    public function duplicate(int $qrId, int $ruleId, int $userId): int { return $this->get($qrId, $ruleId) ? $this->rules->duplicate($ruleId, $userId) : 0; }
    public function move(int $qrId, int $ruleId, string $direction, int $userId): bool { return $this->get($qrId, $ruleId) ? $this->rules->move($ruleId, $direction, $userId) : false; }
}
