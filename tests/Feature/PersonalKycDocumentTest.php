<?php

use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

it('returns usable personal document links after registration and in the owner profile', function (): void {
    Storage::fake('local');
    $owner = User::factory()->create();
    $documents = [
        'pan' => UploadedFile::fake()->create('pan.pdf', 10, 'application/pdf'),
        'citizenship_front' => UploadedFile::fake()->image('front.jpg'),
        'citizenship_back' => UploadedFile::fake()->image('back.png'),
        'nid' => UploadedFile::fake()->image('nid.png'),
    ];
    $payload = [
        'name' => 'Document Test Spa', 'phone_number' => '9800000000',
        'locations' => [[
            'address' => 'Street 12', 'city' => 'Kathmandu', 'latitude' => 27.7, 'longitude' => 85.3, 'service_availability' => 'on_site',
        ]],
        'personal_kyc_document' => $documents,
    ];
    foreach (['vat_pan_document', 'business_registration_document', 'ward_registration_document', 'municipality_registration_document'] as $type) {
        $payload[$type] = UploadedFile::fake()->image($type.'.png');
    }

    $registration = $this->actingAs($owner, 'sanctum')->post('/api/v1/business/register', $payload, ['Accept' => 'application/json'])
        ->assertCreated()->assertJsonCount(4, 'data.personal_kyc.documents');

    $links = $registration->json('data.personal_kyc.documents');
    $this->getJson('/api/v1/users/me')->assertOk()->assertJsonPath('data.personal_kyc.documents', $links)
        ->assertJsonMissingPath('data.kyc_document');
    foreach ($links as $document) {
        $extension = $document['type'] === 'pan' ? 'pdf' : ($document['type'] === 'citizenship_front' ? 'jpg' : 'png');
        $view = $this->get($document['view_url'])->assertOk()->assertHeader('Content-Disposition', 'inline; filename='.$document['type'].'.'.$extension)
            ->assertHeader('X-Content-Type-Options', 'nosniff');
        expect($view->headers->get('Cache-Control'))->toContain('private', 'no-store');
        expect($view->streamedContent())->toBe(file_get_contents($documents[$document['type']]->getRealPath()));
    }
    Storage::disk('local')->assertExists(array_values($owner->refresh()->kyc_document));

    foreach ($registration->json('data.kyc.documents') as $document) {
        $view = $this->get($document['view_url'])->assertOk()->assertHeader('Content-Disposition', 'inline; filename='.$document['type'].'.png');
        expect($view->streamedContent())->toBe(file_get_contents($payload[$document['type']]->getRealPath()));
    }
});

it('requires authentication for personal KYC views', function (): void {
    $this->getJson('/api/v1/users/me/kyc-documents/pan')->assertUnauthorized();
});

it('does not serve another users personal document using its view URL', function (): void {
    Storage::fake('local');
    $owner = User::factory()->create();
    $path = 'user-kyc/'.$owner->id.'/pan.pdf';
    Storage::disk('local')->put($path, 'private owner document');
    $owner->forceFill(['kyc_document' => ['pan' => $path]])->save();
    $url = $owner->kycSummary()['documents'][0]['view_url'];
    $other = User::factory()->create();

    $this->actingAs($other, 'sanctum')->getJson($url)->assertNotFound();

    $this->getJson('/api/v1/users/me')->assertOk()->assertJsonPath('data.personal_kyc.documents', []);
    Storage::disk('local')->assertExists($path);
});

it('returns 404 for unsupported or missing personal documents', function (string $type): void {
    Storage::fake('local');
    $owner = User::factory()->create();
    $owner->forceFill(['kyc_document' => ['pan' => 'user-kyc/missing.pdf']])->save();

    $this->actingAs($owner, 'sanctum')->getJson('/api/v1/users/me/kyc-documents/'.$type)->assertNotFound();

    expect(Storage::disk('local')->allFiles())->toBe([]);
})->with(['unknown', 'pan', 'nid']);
