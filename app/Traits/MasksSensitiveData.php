<?php
namespace App\Traits;

trait MasksSensitiveData {
    public function getMaskedApplicantDataAttribute() {
        $data = $this->applicant_data;
        if (!is_array($data)) return $data;

        // Mask CURP
        if (isset($data['personal_info']['curp'])) {
            $data['personal_info']['curp'] = $this->maskString($data['personal_info']['curp'], 4, 4);
        }
        // Mask RFC
        if (isset($data['personal_info']['rfc'])) {
            $data['personal_info']['rfc'] = $this->maskString($data['personal_info']['rfc'], 4, 3);
        }
        // Mask ID / INE
        if (isset($data['personal_info']['id_number'])) {
            $data['personal_info']['id_number'] = $this->maskString($data['personal_info']['id_number'], 2, 2);
        }
        
        return $data;
    }

    private function maskString($string, $keepStart = 4, $keepEnd = 4) {
        if (empty($string)) return $string;
        $len = strlen($string);
        if ($len <= ($keepStart + $keepEnd)) return str_repeat('*', $len);
        return substr($string, 0, $keepStart) . str_repeat('*', $len - $keepStart - $keepEnd) . substr($string, -$keepEnd);
    }
}
