<?php

namespace Database\Seeders;

use App\Models\Asset;
use App\Models\Kit;
use App\Models\Lab;
use App\Models\Practice;
use App\Models\User;
use App\Services\OperationService;
use App\Services\ReservationService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class DemoSeeder extends Seeder
{
    public function run(): void
    {
        if (! config('turnolab.demo')) {
            return;
        }
        DB::transaction(function () {
            $roles = ['student' => ['Estudiante demo', 'DEMO-001', 'estudiante'], 'teacher' => ['Docente demo', 'DOC-001', 'docente'], 'technician' => ['Técnico demo', 'TEC-001', 'tecnico'], 'admin' => ['Coordinación demo', 'ADM-001', 'admin']];
            foreach ($roles as $role => [$name,$mat,$alias]) {
                User::firstOrCreate(['email' => $alias.'@turnolab.demo'], ['name' => $name, 'matricula' => $mat, 'role' => $role, 'password' => Hash::make(config('turnolab.demo_password')), 'email_verified_at' => now()]);
            }
            for ($i = 2; $i <= 8; $i++) {
                User::firstOrCreate(['email' => "estudiante{$i}@turnolab.demo"], ['name' => "Estudiante demo {$i}", 'matricula' => sprintf('DEMO-%03d', $i), 'role' => 'student', 'password' => Hash::make(config('turnolab.demo_password'))]);
            }
            $lab1 = Lab::firstOrCreate(['name' => 'Laboratorio de Electrónica'], ['location' => 'Edificio A · Planta baja', 'capacity' => 30]);
            $lab2 = Lab::firstOrCreate(['name' => 'Laboratorio de Redes'], ['location' => 'Edificio B · Planta baja', 'capacity' => 24]);
            $lab3 = Lab::firstOrCreate(['name' => 'Laboratorio de Procesos'], ['location' => 'Edificio C · Planta baja', 'capacity' => 24]);
            $teacher = User::where('role', 'teacher')->firstOrFail();
            if (! Kit::exists()) {
                $names = ['medicion' => ['Medición eléctrica', ['Multímetro digital', 'Juego de puntas', 'Pinza amperimétrica', 'Fuente regulable']], 'electronica' => ['Electrónica básica', ['Protoboard', 'Fuente de alimentación', 'Juego de cables', 'Osciloscopio portátil']], 'redes' => ['Redes y conectividad', ['Probador de red', 'Pinza crimpadora', 'Cable de pruebas', 'Adaptador Ethernet']]];
                $assetNumber = 1;
                $kitNumber = 1;
                foreach ($names as $type => [$name,$parts]) {
                    for ($k = 0; $k < 8; $k++) {
                        $kit = Kit::create(['code' => sprintf('KIT-%03d', $kitNumber++), 'name' => $name.' '.($k + 1), 'type' => $type, 'location' => 'A-'.sprintf('%02d', $k + 1), 'description' => 'Kit didáctico de cuatro componentes. Registro ficticio para el UPTEX CHALLENGE.']);
                        foreach ($parts as $part) {
                            Asset::create(['code' => sprintf('EQ-%04d', $assetNumber++), 'name' => $part, 'kit_id' => $kit->id, 'category' => $type, 'abc' => 'A', 'location' => $kit->location, 'status' => 'available', 'diagnosed_at' => now()->subDay(), 'value' => 2500]);
                        }
                    }
                }
                for ($i = $assetNumber; $i <= 640; $i++) {
                    Asset::create(['code' => sprintf('EQ-%04d', $i), 'name' => ['Instrumento de medición', 'Herramienta de laboratorio', 'Módulo de prácticas', 'Accesorio técnico'][$i % 4].' '.$i, 'category' => ['medicion', 'electronica', 'redes'][$i % 3], 'abc' => $i < 350 ? 'B' : 'C', 'location' => ($i < 350 ? 'B-' : 'C-').sprintf('%02d', $i % 20 + 1), 'status' => $i > 550 ? 'pending_review' : 'available', 'diagnosed_at' => $i > 550 ? null : now()->subDays($i % 20 + 1), 'value' => 1500 + $i % 10 * 250]);
                }
            }
            if (! Practice::exists()) {
                $base = now()->addDay()->startOfDay();
                for ($d = 0; $d < 7; $d++) {
                    foreach ([
                        ['Circuitos y medición', $lab1, 'medicion', 9], ['Automatización básica', $lab1, 'electronica', 11], ['Instrumentación de procesos', $lab3, 'medicion', 13], ['Cableado estructurado', $lab2, 'redes', 9], ['Diagnóstico de redes', $lab2, 'redes', 11],
                    ] as [$title,$lab,$type,$hour]) {
                        $start = $base->copy()->addDays($d)->hour($hour);
                        Practice::create(['title' => $title, 'lab_id' => $lab->id, 'teacher_id' => $teacher->id, 'kit_type' => $type, 'starts_at' => $start, 'ends_at' => $start->copy()->addMinutes(100), 'capacity' => 20, 'instructions' => 'Revisa el kit antes de iniciar. Devuelve todos los componentes al concluir la práctica.']);
                    }
                }
                $practice = Practice::where('kit_type', 'medicion')->orderBy('starts_at')->first();
                $kits = Kit::where('type', 'medicion')->take(4)->get();
                $students = User::where('role', 'student')->orderBy('id')->take(4)->get();
                $rules = app(ReservationService::class);
                $ops = app(OperationService::class);
                $tech = User::where('role', 'technician')->first();
                foreach ($kits as $i => $kit) {
                    $r = $rules->reserve($students[$i], $practice->id, $kit->id, $practice->starts_at->copy()->subMinutes(45)->toDateTimeString());
                    $rules->prepare($tech, $r);
                    if ($i > 0) {
                        $loan = $ops->deliver($tech, $r, ['matricula' => $students[$i]->matricula, 'kit_code' => $kit->code, 'components' => $kit->assets->pluck('id')->all(), 'assisted' => true, 'reason' => 'Carga inicial de datos ficticios del reto.'], now()->subMinutes(25));
                        if ($i === 2) {
                            $loan->update(['due_at' => now()->subMinutes(5)]);
                        }
                        if ($i === 3) {
                            $ops->receive($tech, $loan, ['components' => $kit->assets->pluck('id')->all(), 'checked' => false, 'notes' => 'Datos ficticios: diagnóstico pendiente.'], now()->subMinutes(3));
                        }
                    }
                }
            }
        });
    }
}
