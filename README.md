# I tuoi professionisti digitali: connector

Il contratto di [I tuoi professionisti digitali](https://ituoiprofessionistidigitali.it) in forma di codice, per i sistemi Laravel che si collegano al portale: DTO, client, firma dei webhook, validazione dei payload e comando di installazione.

Il portale è l'autorità sul contratto. Il pacchetto lo segue: se un dato non rispetta il contratto, la richiesta non parte. Il contratto completo, con ogni regola e il suo codice (E6, W4…), è `docs/contratto.md` nel repo del portale.

## Requisiti

PHP 8.4 o 8.5, Laravel 13.

## Installazione

```bash
composer require ituoiprofessionistidigitali/connector
php artisan platform:install
```

Il comando pubblica `config/platform.php` e deposita le istruzioni per gli agenti AI: in `.ai/rules/platform-connector.md` se il progetto usa quella convenzione, altrimenti in `AGENTS-platform-connector.md`. Dentro `vendor/` nessun agente le leggerebbe.

## Credenziali

Le rilascia l'operatore del portale, che le vede una volta sola:

```dotenv
PLATFORM_URL=https://staging.ituoiprofessionistidigitali.it
PLATFORM_CLIENT_ID=0199b6ef-…
PLATFORM_CLIENT_SECRET=…
PLATFORM_SIGNING_SECRET=…
```

Senza queste chiavi il sistema non è collegato e nulla si rompe: `ConnectorConfig::isConnected()` dice se usare il portale.

| Chiave | Predefinito | Cosa fa |
| --- | --- | --- |
| `PLATFORM_WEBHOOK_PATH` | `platform/webhook` | Percorso della rotta che riceve gli eventi; vuoto per non registrarla |
| `PLATFORM_REPLY_TO_PINGS` | `true` | Risponde da solo ai `platform.ping` degli aderenti |
| `PLATFORM_QUEUE` | coda predefinita | Coda della risposta ai ping |
| `PLATFORM_VALIDATE_PAYLOADS` | `true` | Controlla il payload sullo schema del catalogo prima di mandarlo |
| `PLATFORM_CATALOG_TTL` | `3600` | Secondi di cache del catalogo usato dalla validazione |
| `PLATFORM_TIMEOUT`, `PLATFORM_RETRIES` | `10`, `2` | Timeout in secondi e nuovi tentativi su errori 5xx e di rete |

## Collegamento

```php
use ITuoiProfessionistiDigitali\Connector\Facades\Platform;

// Dopo il deploy, o quando cambia l'URL del webhook
$system = Platform::present(route('platform.webhook'));

$system->status;   // SystemStatus::Pending finché il portale non ha verificato il webhook
```

La verifica la gestisce il pacchetto: la rotta `platform.webhook` risponde alla sfida del portale.

## Aderenti

```php
use ITuoiProfessionistiDigitali\Connector\Data\MemberData;

$members = Platform::syncMembers([
    MemberData::validateAndCreate([
        'external_ref' => 'struttura-1',
        'subject_type' => 'organization',
        'name' => 'Studio Rossi e Associati',
        'vat_number' => '01234567897',
        'tax_code' => null,
        'municipality' => 'Bari',
        'province' => 'BA',
        'typologies' => ['commercialisti'],
        'listed' => true,
    ]),
]);

$members[0]->id;   // l'id del portale: il sender dei tuoi eventi
```

L'elenco è **completo**: gli aderenti che mancano diventano inattivi.

Due `syncMembers()` contemporanei dello stesso sistema non si mescolano: il portale li applica uno dopo l'altro e vince l'ultimo (M6). Un rifiuto è una `PlatformRequestException` con le chiavi dei campi in `->errors`, da correggere e non da ritentare.

```php
$page = Platform::searchMembers(typology: 'commercialisti', search: 'bianchi');

foreach ($page->members as $member) { /* id, name, vat_number, municipality, province, typologies */ }

$next = Platform::searchMembers(typology: 'commercialisti', cursor: $page->nextCursor);
```

`searchMembers()` non mostra mai il codice fiscale, `external_ref` né il sistema (M11, M12). Un aderente di un altro sistema si legge anche per id, per esempio il committente di un'offerta ricevuta (M16):

```php
use ITuoiProfessionistiDigitali\Connector\Exceptions\MemberNotFoundException;

$committente = Platform::member($envelope->sender);   // ListedMemberData
```

Risponde per un aderente pubblicato di un altro sistema attivo, o legato al sistema da una transazione; ogni altro caso, compreso un aderente del sistema stesso, è `MemberNotFoundException`, senza dire perché.

## Eventi

```php
use ITuoiProfessionistiDigitali\Connector\Data\EnvelopeData;

$ping = EnvelopeData::make(
    type: 'platform.ping',
    schemaVersion: 1,
    sender: $mioAderente,
    recipient: $altroAderente,
    typology: 'commercialisti',
    payload: ['challenge' => bin2hex(random_bytes(12))],
);

$accepted = Platform::send($ping);   // 202 la prima volta, 200 se la stessa busta era già arrivata
```

Salva la busta prima di mandarla: dopo un timeout si rimanda la stessa, con lo stesso `event_id`.

L'outbox degli eventi lo fa per te. Chiama `queue()` nella stessa transazione del database del cambio che produce l'evento: la busta parte solo se il cambio è salvato, dopo il commit, in coda, sempre identica a ogni tentativo.

```php
use Illuminate\Support\Facades\DB;
use ITuoiProfessionistiDigitali\Connector\Outbox\EventOutbox;

DB::transaction(function () use ($busta, $incarico): void {
    $incarico->save();
    resolve(EventOutbox::class)->queue($busta);
});
```

- Il job `SendPlatformEvent` ritenta da solo gli errori di rete, i 5xx e il 403 di un sistema non ancora attivo; `platform:send-outbox` rimette in coda ogni cinque minuti gli eventi rimasti `pending`.
- Un 422 o un 409, del portale o del pacchetto prima della richiesta, porta la riga di `platform_event_outbox` in `failed`, con `last_error`: è un errore nel codice, da correggere, non da ritentare.
- La stessa busta passata due volte si tiene una volta sola; lo stesso `event_id` con un altro contenuto è una `LogicException`.
- Nei test: `PlatformOutbox::assertEventQueued($tipo, fn (EnvelopeData $busta): bool => …)` e `PlatformOutbox::assertEventNotQueued($tipo)`.
- La tabella è nuova: dopo l'aggiornamento del pacchetto lancia `php artisan migrate`.

Per ricevere, ascolta `PlatformEventReceived`, in coda e in modo idempotente su `event_id`:

```php
use Illuminate\Contracts\Queue\ShouldQueue;
use ITuoiProfessionistiDigitali\Connector\Events\PlatformEventReceived;

final class HandlePlatformEvent implements ShouldQueue
{
    public function handle(PlatformEventReceived $event): void
    {
        $envelope = $event->envelope;   // EnvelopeData con firma già verificata
    }
}
```

Il portale non genera eventi di business: da sé manda solo il `platform.ping` della verifica, a cui risponde il pacchetto. Il catalogo tiene `sent_by` (`EventTypeData::$sent_by`) per i tipi che il portale potrà mandare in futuro, e `Platform::send()` rifiuta un tipo con `sent_by: platform` prima di mandarlo (E5). Ogni altra busta senza mittente viene da un portale più recente: il webhook risponde 202, la scrive nel log come `warning` (id, tipo e versione, mai il payload) e non la passa all'applicazione. Così il portale non la ripete per un giorno (W5, W6). Quando compare quel `warning`, aggiorna il pacchetto. Una busta che non si legge ha ancora 400.

### Eventi fra studi

Un incarico affidato a uno studio di un **altro** sistema si porta avanti con eventi fra i due sistemi; il portale li controlla sul registro e li inoltra, ma non decide nulla (E13). Con A il sistema del committente e B quello dello studio:

1. A registra la transazione `invited`, con lo studio come controparte (`CounterpartyData::member($idAderente)`), e nella stessa transazione del database accoda `coworking.assignment_offered`.
2. B riceve l'offerta con la transazione letta dal registro (`$event->transaction`), decide nel suo sistema e risponde una volta sola con `coworking.assignment_accepted` o `coworking.assignment_declined`, con l'istante della risposta.
3. A, nel listener della risposta, registra la transazione `accepted` o `declined` con `responded_at` uguale ad `answered_at`. Il portale rifiuta questo passaggio prima della risposta di B (R24).
4. Ognuno manda all'altro `coworking.contract_details`; i due studi firmano fuori dal portale e A registra `signed_at`.
5. A manda `coworking.activities_assigned`; B manda `coworking.activity_status`, che A riporta nei suoi `PUT`.

A può revocare finché l'incarico non è concluso, prima o dopo la risposta: registra `revoked`, con `closed_at`, e accoda `coworking.assignment_revoked` con `revoked_at` uguale a `closed_at`. Una risposta di B arrivata dopo la revoca registrata riceve 422: l'offerta non c'è più.

| Tipo | Payload | Chi lo manda |
| --- | --- | --- |
| `coworking.assignment_offered` | `AssignmentOfferedData`: la transazione (`TransactionIdData`) | Il committente, con il registro `invited` |
| `coworking.assignment_accepted` | `AssignmentAcceptedData`: la transazione e `answered_at` | Lo studio, una volta, con il registro `invited` |
| `coworking.assignment_declined` | `AssignmentDeclinedData`: la transazione, `answered_at` e `reason` (fino a 1000 caratteri, o `null`) | Lo studio, una volta, con il registro `invited` |
| `coworking.assignment_revoked` | `AssignmentRevokedData`: la transazione e `revoked_at` | Il committente, con il registro già `revoked` |
| `coworking.contract_details` | `ContractDetailsData`: lo studio (`PartyData`, con la sede in `AddressData`) e chi firma (`SignatoryData`) | Tutti e due, dopo l'accettazione |
| `coworking.activities_assigned` | `ActivitiesAssignedData`: i processi (`AssignedProcessData`) col cliente finale (`FinalClientData`), le attività (`AssignedActivityData`) e le guide (`GuideData`) | Il committente, dopo la firma |
| `coworking.activity_status` | `ActivityStatusData`: un'attività `completed` o `reopened` (`ActivityStatusChange`), con l'istante | Lo studio |

Ogni evento ha `correlation_id` uguale all'id della transazione e la sua tipologia. L'id lo dà il pacchetto alla transazione dal primo salvataggio: `$model->platformTransactionId()` (R23).

```php
use ITuoiProfessionistiDigitali\Connector\Data\AssignmentOfferedData;
use ITuoiProfessionistiDigitali\Connector\Outbox\EventOutbox;

DB::transaction(function () use ($proposta): void {
    $proposta->save();   // RecordsOnPlatform: la transazione invited entra nella outbox, con il suo id

    $offerta = AssignmentOfferedData::from(['transaction' => ['id' => $proposta->platformTransactionId()]])
        ->toEnvelope(sender: $mioAderente, recipient: $studio->platform_id, typology: $proposta->typology);

    resolve(EventOutbox::class)->queue($offerta);
});
```

- **Ordine.** Un evento che cita una transazione della outbox delle transazioni parte solo dopo che il portale ne ha confermato l'ultima versione salvata: aspetta in `pending`, con il motivo in `last_error`, e la conferma lo rimette in coda. Se il portale ha rifiutato la transazione, anche l'evento finisce `failed`. Salva il modello prima di accodare l'evento, nella stessa transazione del database.
- **Ricezione di un'offerta.** Il webhook risponde subito; un job in coda (`ReceivePlatformEvent`) legge la transazione con `Platform::transaction($id)` e lancia `PlatformEventReceived` con `$event->transaction` (`RecordedTransactionData`): i termini, i prezzi e la controparte come il registro li ha. Se il registro non la mostra ancora, il job ritenta.
- **La transazione è del committente**: il sistema dello studio non la registra e non scrive nel registro; la sua risposta la registra il committente.
- `toEnvelope()` controlla il payload sullo schema del pacchetto prima di costruire la busta: fuori schema è una `PlatformRequestException` 422, come la darebbe il portale.
- `validateAndCreate()` controlla anche il carattere di controllo di partita IVA e codici fiscali (M4) e il cellulare in E.164.
- In `coworking.activities_assigned` ogni processo porta il `process.reference` delle sue attività nella transazione (K1): lo studio le raggruppa come il committente.
- Ricevendoli, leggi il payload con il DTO del tipo: `AssignmentAcceptedData::from($envelope->payload)`.

### File fra sistemi

Un evento fra studi può portare documenti, come gli allegati delle guide di `coworking.activities_assigned`. Il file non viaggia nella busta: sta sul portale per il tempo del transito, e l'evento lo cita con il suo id, nel payload e in `files` della busta (E12, F1–F11).

```php
use ITuoiProfessionistiDigitali\Connector\Facades\Platform;

$file = Platform::uploadFile(
    uploadId: (string) Str::uuid7(),          // la chiave di idempotenza (F2): salvala per i nuovi tentativi
    sender: $mioAderente,
    recipient: $altroStudio,
    disk: 's3',
    path: 'guide/fatture.pdf',
    name: 'Guida registrazione fatture.pdf',
    mediaType: 'application/pdf',
);                                            // FileData, uploaded, da citare entro citable_until (24 ore)

Platform::fileStatus($file->id);              // FileData con lo stato: uploaded, sent, received o expired (FileStatus)
```

- `uploadFile()` legge il file dal disco del sistema e ne calcola lo SHA-256 senza tenerlo in memoria. Ripete da solo gli errori di rete, i 5xx e un 429 breve, riaprendo il file a ogni tentativo. Un 410 vuol dire un `upload_id` già scaduto: ne serve uno nuovo.
- `EnvelopeData::make(..., files: [$file->id])` elenca i file della busta. I DTO dei `coworking.*` lo fanno da soli: `toEnvelope()` mette in `files` gli id che il payload cita. Il pacchetto controlla prima dell'invio che siano esattamente quelli del payload, al massimo 20 (E12).
- Ricevendo, `$envelope->fileReferences()` dà i file della busta consegnata (`FileReferenceData`, con nome, tipo, dimensione, SHA-256 e `available_until`). `Platform::downloadFile($riferimento, $disco, $percorso)` scarica e salva solo se lo SHA-256 è quello della busta, altrimenti `FileIntegrityException`; un file scaduto o già confermato è `FileGoneException`. `Platform::confirmFile($id, $sha256)` conferma, e il portale cancella il file (F8).
- `reply()` non copia mai i file dell'evento a cui risponde.
- `PLATFORM_FILE_TIMEOUT` (predefinito 120 secondi) è il tempo massimo di un caricamento.

Con l'outbox degli eventi non serve caricare a mano. Ogni file del sistema è un `OutgoingFileData` con un `ref` provvisorio: citalo nel payload dove andrebbe l'id del file, e passa i file a `queue()`.

```php
use ITuoiProfessionistiDigitali\Connector\Data\OutgoingFileData;

$allegato = OutgoingFileData::make('s3', 'guide/fatture.pdf', 'Guida registrazione fatture.pdf', 'application/pdf');

$busta = ActivitiesAssignedData::validateAndCreate([
    // ...
    'guides' => [['title' => 'Registrazione fatture', 'body' => $testo, 'attachments' => [$allegato->ref]]],
])->toEnvelope($mioAderente, $studioScelto, $transazione->typology);

DB::transaction(function () use ($busta, $allegato): void {
    // il cambio che produce l'evento, poi:
    resolve(EventOutbox::class)->queue($busta, [$allegato]);
});
```

- `queue()` legge lo SHA-256 di ogni file subito, e salva i file in `platform_event_outbox_files` (una tabella nuova: `php artisan migrate`). I file della busta devono essere esattamente i `ref` passati, altrimenti `LogicException`.
- Il job carica i file e manda l'evento con gli id del portale al posto dei `ref`, nel payload e in `files`. Dopo un tentativo che può essere arrivato chiede lo stato dei file (F5): `sent` vuol dire che l'evento è arrivato, e rimanda la stessa busta; `expired` li ricarica con un `upload_id` nuovo.
- Ricevendo, il pacchetto fa tutto da solo. Un evento con file ha subito il suo 2xx dal webhook, e un job in coda (`ReceivePlatformEvent`, uno per `event_id`) scarica ogni file in `platform-files/{event_id}/{file_id}` sul disco `PLATFORM_FILES_DISK`, lo verifica sullo SHA-256 della busta e lo conferma. Solo dopo lancia `PlatformEventReceived`, con `$event->files`: un `ReceivedFileData` per file, con `disk`, `path`, nome, tipo e SHA-256. Il listener sposta i file nello storage del sistema. Un file che il portale non ha più arriva con `isMissing()` e `path` a `null`: chiedilo di nuovo allo studio che lo ha mandato.
- Un file cambiato o sparito dopo `queue()` porta la riga in `failed`, con il percorso in `last_error`: il file va mandato con un evento nuovo. Anche un file rifiutato dal portale (422, per esempio il tipo, o 413 oltre i 25 MB) è `failed`. La quota piena (429, F11) rimette l'evento in coda dopo `Retry-After`.

## Registro delle transazioni

Ogni incarico inviato si registra sul portale quando nasce, e poi a ogni cambio (regola R9). Ogni PUT è la fotografia completa della transazione, quindi il portale accetta la prima registrazione in qualunque stato, purché coerente con le sue date e con le sue attività. Lo stesso dato copre i due casi:

- la controparte c'è sempre, scelta dal sistema: una persona (un collaboratore, o un consulente trovato con `Platform::searchProfessionals()`) o un aderente, dello stesso sistema o di un altro (R3);
- gli stati sono `invited`, `accepted`, `declined`, `revoked` e `completed`. Con un aderente di un altro sistema, `accepted` e `declined` seguono la sua risposta (R24, vedi «Eventi fra studi»).

La struttura è una sola: tutte le chiavi ci sono sempre, anche quando valgono `null`, nella testata (`responded_at`, `signed_at`, `closed_at`), nella controparte, nelle attività, nel compenso e nella descrizione. Una chiave mancante è un 422; i DTO del pacchetto le emettono tutte.

Ogni transazione ha un `id`, un UUID v7 in minuscolo che la outbox genera al primo salvataggio e rimanda identico a ogni revisione (R23): gli eventi fra studi lo citano. `$model->platformTransactionId()` lo dà già dentro la transazione del database del salvataggio, prima di qualunque risposta del portale.

`signed_at` è la data della firma dell'incarico, a mano o elettronica, che il sistema registra (R8, R22). È `null` in `invited` e `declined`; facoltativa in `accepted`, dove `null` vuol dire «accettato, in attesa di firma», e in `revoked`; obbligatoria in `completed`. Sta fra `responded_at`, che vuole, e `closed_at`. La firma viene prima del lavoro: senza firma nessuna attività è `completed` e i minuti lavorati sono `null` o `0`, anche in un'attività `revoked`; un'attività `completed` non chiude prima della firma. Una volta mandata, la firma non torna `null`: il pacchetto non conosce la revisione precedente, il controllo lo fa il portale (R13).

Ogni transazione ha le sue attività (`TransactionActivityData`), ognuna con il proprio compenso a ore o a corpo, i minuti previsti (sempre obbligatori), lo stato, i minuti lavorati alla chiusura e la descrizione con i soli nomi del catalogo. `totalCents()` dà il totale come lo calcola il portale.

La testata porta anche `title` (testo semplice, da 1 a 255 caratteri) e `description` (Markdown, al massimo 10000 caratteri; l'HTML dentro il testo non è un errore). Sono obbligatori e non vuoti in ogni stato. Regola R6: niente testo libero nelle descrizioni delle attività; `title` e `description` sono testi dello studio, senza dati del cliente.

I DTO sono tipizzati e non conoscono i modelli del sistema: è il sistema che mappa i suoi modelli su di loro, di solito in una sola classe.

| DTO | Cosa porta |
| --- | --- |
| `TransactionData` | La testata dell'invio, con `title` e `description` dello studio, e le sue attività |
| `CounterpartyData` | `CounterpartyData::person($codiceFiscale, $nome, $cognome, $email, $partitaIva, $comune, $provincia)`, con l'email obbligatoria e gli ultimi tre facoltativi: l'anagrafica al momento dell'invio, identica a ogni revisione (R13). Per un consulente registrato, `$consulente->toCounterparty()`. Oppure `CounterpartyData::member($idAderente)`, di questo sistema o di un altro. L'email della persona va in minuscolo, come il portale la salva e la restituisce (T6). Sul filo ha sempre le nove chiavi `type`, `member_id`, `tax_code`, `first_name`, `last_name`, `email`, `vat_number`, `municipality`, `province` |
| `TransactionActivityData` | Un'attività: riferimento, compenso, minuti, stato, chiusura, descrizione |
| `CompensationData` | `CompensationData::hourly($centesimiAllOra)` o `CompensationData::fixed($centesimi)` |
| `ActivityDescriptionData` | Il processo dell'attività (`ProcessData`, obbligatorio), il nome dell'attività nel catalogo, o `null`, e la scadenza `Y-m-d` |
| `ProcessData` | Il processo di ogni attività: `reference`, l'identificativo opaco del sistema (da 1 a 191 caratteri, mai un nome), stabile per ogni istanza del soggetto (processo di linea o di staff, progetto, agenda) e uguale per tutte le sue attività, così lo studio che le riceve le raggruppa nello stesso processo; `name`, il nome del processo nel catalogo, o `null` se non ne ha uno |

```php
$diretto = new TransactionData(
    assignment_reference: $incarico->uuid,
    principal: $idAderente,
    counterparty: CounterpartyData::person('RSSMRA80A01H501U', 'Mario', 'Rossi', 'mario.rossi@example.com', municipality: 'Bari', province: 'BA'),
    typology: 'commercialisti',
    title: 'Contabilità ordinaria 2026',
    description: 'Registrazione delle fatture del 2026.',
    status: TransactionStatus::Invited,
    sent_at: CarbonImmutable::parse($proposta->sent_at),
    activities: [new TransactionActivityData(
        reference: (string) $riga->id,
        compensation: CompensationData::hourly(4500),
        estimated_minutes: 120,
        status: TransactionActivityStatus::Open,
        description: new ActivityDescriptionData(new ProcessData((string) $processo->id, 'Contabilità ordinaria'), 'Registrazione fatture', '2026-11-30'),
    )],
);
```

I minuti lavorati dipendono dal tipo della controparte: con una persona sono obbligatori in un'attività `completed`, con un aderente sono sempre `null` (R5).

La parte ferma all'invio (controparte, tipologia, testi, prezzi) non cambia fra una revisione e l'altra (R13). Il modello che registra la transazione la costruisce da quello che ha fotografato all'invio, non dalle anagrafiche di oggi; un cambio dell'anagrafica si dichiara a parte (vedi «Anagrafica dei professionisti»). `TransactionData::from()` legge anche il corpo del portale, con la descrizione nella forma dello schema (`{"process": {"reference": "…", "name": "…"}, "activity": {"name": "…"}}`).

Il modello che corrisponde all'invio lo dichiara con un'interfaccia, e il pacchetto fa il resto:

```php
use ITuoiProfessionistiDigitali\Connector\Concerns\RecordsOnPlatform;
use ITuoiProfessionistiDigitali\Connector\Contracts\RecordsPlatformTransaction;
use ITuoiProfessionistiDigitali\Connector\Data\TransactionData;

final class ExternalAssignmentProposal extends Model implements RecordsPlatformTransaction
{
    use RecordsOnPlatform;

    public function platformTransactionReference(): ?string
    {
        return $this->uuid; // null per una bozza mai inviata
    }

    public function toPlatformTransaction(): TransactionData
    {
        return resolve(ProposalMapper::class)->toTransaction($this); // la mappatura del sistema
    }
}
```

I modelli che cambiano la transazione senza esserlo, come le sue righe o l'incarico che la contiene, la registrano di nuovo a ogni salvataggio ed eliminazione:

```php
use ITuoiProfessionistiDigitali\Connector\Concerns\RecordsAffectedOnPlatform;
use ITuoiProfessionistiDigitali\Connector\Contracts\AffectsPlatformTransactions;

final class ExternalAssignmentActivity extends Model implements AffectsPlatformTransactions
{
    use RecordsAffectedOnPlatform;

    public function affectedPlatformTransactions(): iterable
    {
        return ExternalAssignmentProposal::query()->whereKey($this->proposal_id)->get();
    }
}
```

- Un trait usato senza la sua interfaccia lancia una `LogicException`.
- Ogni salvataggio scrive la versione attuale in una outbox (`php artisan migrate` crea la tabella `platform_transaction_outbox`), nella stessa transazione del database, con una revisione nuova solo se qualcosa è cambiato. Le date si confrontano in UTC, come le restituisce il portale: lo stesso istante scritto in un altro fuso non è un cambio. Una bozza, con il riferimento a `null`, non ci arriva. L'outbox tiene solo l'ultima versione: se l'invio di `invited` non è riuscito e intanto l'incarico è stato accettato, al portale arriva come prima registrazione la versione `accepted`, ed è accettata.
- Un job in coda la manda con `PUT /transactions/{reference}`, con il suo `id`. Riprova sugli errori di rete e su un sistema non ancora attivo; si ferma, segnando l'errore, su una violazione del contratto o un conflitto. Vale anche quando la violazione la trova il pacchetto prima della richiesta, per esempio su una versione salvata prima che cambiasse una regola: la riga finisce `failed` e `platform:send-outbox` non la riprende.
- `$model->isRecordedOnPlatform()` dice se il portale ha confermato la versione attuale. La registrazione segue il lavoro, non lo blocca.
- Ogni cinque minuti il comando schedulato `platform:send-outbox` rimanda le versioni rimaste indietro, per esempio mentre il sistema era in attesa di verifica.
- Una transazione non si cancella: un invio ritirato si salva come `revoked`, con tutte le attività chiuse.
- Lo storico si carica con `Platform::recordTransactions()`, fino a 500 per chiamata, con le stesse regole della PUT (R10). Il pacchetto dà a ogni transazione il suo `id` prima della richiesta, e una transazione nuova entra nella outbox come da mandare: se il batch non risponde, `platform:send-outbox` la manda con lo stesso id; una che il portale rifiuta finisce `failed` con il suo errore. Ogni transazione che il portale ha (`created`, `updated`, `unchanged`) entra nella outbox come già confermata, così la outbox conosce ogni persona incaricata.
- `Platform::transactions()` legge le transazioni in cui il committente o la controparte è un aderente del sistema, con i filtri `status` e `updatedSince`. `Platform::transaction($id)` ne legge una per id, anche dalla parte della controparte; se il sistema non la vede, `TransactionNotFoundException` (il portale risponde con un elenco vuoto, mai 404).
- `Platform::recordTransaction($reference, $id, $transazione, $revisione)` fa la sola chiamata, senza outbox: l'`id` dev'essere un UUID in minuscolo, lo stesso a ogni revisione.
- Nei test del sistema `PlatformOutbox::assertRecorded($model)` verifica che la versione attuale sia nella outbox, `PlatformOutbox::assertNotRecorded($model)` che una bozza non ci sia.

## Consulenti registrati

I consulenti che si registrano sul portale e danno il consenso a essere trovati dagli studi si cercano senza contatti, e si leggono uno alla volta con i dati per affidare un incarico (N1–N5). L'incarico è poi come ogni incarico a persona: il sistema lo registra con la controparte persona e lo porta avanti da sé.

```php
$pagina = Platform::searchProfessionals(typology: 'commercialisti', province: 'BA', search: 'rossi');   // ProfessionalPage

foreach ($pagina->professionals as $consulente) { /* ListedProfessionalData: id, nome, cognome, comune, provincia, tipologie, tax_code_verified */ }

$consulente = Platform::professional($id);   // ProfessionalData, con codice fiscale, email, cellulare e partita IVA
$controparte = $consulente->toCounterparty();
```

- L'elenco non ha contatti: codice fiscale, email, cellulare e partita IVA escono solo dal dettaglio (N3).
- Il portale registra ogni lettura del dettaglio (N5): leggilo quando lo studio sta per affidare l'incarico, non per riempire un elenco. I contatti sono dati personali: niente cache, niente log, niente copie fuori dall'anagrafica che il sistema crea per l'incarico.
- Un consulente che non è più consultabile, anche perché ha revocato il consenso, è `ProfessionalNotFoundException`: il portale non dice perché.
- `tax_code_verified: false` vuol dire un codice fiscale dichiarato, con il solo controllo formale.
- L'anagrafica di un consulente registrato è bloccata sul portale (R20): l'incarico la fotografa così com'è.

## Anagrafica dei professionisti

Il professionista è uno per codice fiscale in tutto il portale. Nasce dalla prima registrazione di un incarico a persona, con l'anagrafica dell'invio. Quando poi l'anagrafica cambia nel sistema, il sistema la dichiara con `PUT /professionals/{tax_code}` (regola R18), datata dal momento del cambio: vince la dichiarazione più recente, non l'ultima arrivata (R19). Le transazioni non cambiano: ognuna conserva l'anagrafica del suo invio (R13).

```php
use ITuoiProfessionistiDigitali\Connector\Data\ProfessionalRecordData;
use ITuoiProfessionistiDigitali\Connector\Outbox\ProfessionalOutbox;

resolve(ProfessionalOutbox::class)->declare($collaboratore->codice_fiscale, new ProfessionalRecordData(
    first_name: 'Mario',
    last_name: 'Rossi',
    declared_at: CarbonImmutable::parse($collaboratore->updated_at),
    email: 'mario.rossi@example.com',
    vat_number: '01234567897',
    municipality: 'Lecce',
    province: 'LE',
));
```

- L'anagrafica è sempre completa: un campo facoltativo a `null` toglie il dato sul portale. Nome, cognome ed email sono obbligatori, come nella controparte persona, e l'email va in minuscolo (T6); facoltativi restano partita IVA, comune e provincia. `declared_at` non può essere oltre 5 minuti nel futuro.
- Una persona mai incaricata non arriva al portale: senza una riga della outbox delle transazioni con quel codice fiscale, in qualunque stato, `declare()` non tiene né manda nulla, non ne controlla nemmeno i dati e restituisce `null`. Il sistema può chiamarla a ogni cambio dell'anagrafica, senza condizioni sue, anche per un referente senza email, con `email: ''`. Per una persona incaricata, invece, dati non validi sono una `ValidationException`. Il codice fiscale si confronta in maiuscolo.
- L'outbox delle dichiarazioni (tabella `platform_professional_outbox`) tiene l'ultima per codice fiscale: ignora una dichiarazione più vecchia e una con la stessa anagrafica. La manda in coda dopo il commit.
- Il portale risponde 404 finché non ha una transazione a persona del sistema con quel codice fiscale. Se l'outbox delle transazioni ne ha ancora di mai confermate, la dichiarazione aspetta e riparte da sola quando una è confermata; altrimenti si chiude come `discarded`, con un log, e non è un errore (per esempio un codice fiscale corretto dopo l'invio).
- Un 204 chiude la dichiarazione anche quando il portale tiene la sua anagrafica, più recente o bloccata (R20): il sistema non lo sa e non deve saperlo.
- `Platform::declareProfessional($codiceFiscale, $anagrafica)` fa la sola chiamata, senza outbox: un 404 diventa `ProfessionalNotAssignedException`.
- Una dichiarazione che viola il contratto, anche se la trova il pacchetto prima della richiesta, finisce `failed` con l'errore e non si ripete. Vale anche per una dichiarazione salvata prima che cambiasse una regola.
- `platform:send-outbox` rimanda anche le dichiarazioni in attesa. Nei test `PlatformOutbox::assertDeclared($codiceFiscale, $anagrafica)` verifica che sia l'ultima dichiarazione nella outbox.

## Errori

Ogni rifiuto del portale è una `PlatformRequestException`, con `->status`, `->errors` (chiavi puntate come `payload.challenge`), `->reason` per i 403 (`SystemStatus`) e `->existingEvent` per i 409. Lo è anche quando il pacchetto rifiuta prima di mandare per lo schema del payload o per un limite, come le 500 transazioni. Un DTO che viola il contratto è invece una `ValidationException` di Laravel, con le stesse chiavi puntate in `->errors()`, prima di qualunque richiesta. Hanno un'eccezione propria i 404 di `declareProfessional()` (`ProfessionalNotAssignedException`), di `member()` (`MemberNotFoundException`) e di `professional()` (`ProfessionalNotFoundException`), e l'elenco vuoto di `transaction()` (`TransactionNotFoundException`). Il limite dei 1000 aderenti è invece una `ValidationException` su `members`. Anche oltre le 500 attività di una transazione la `ValidationException` è sul solo `activities`, senza gli errori delle singole attività. Senza credenziali il client lancia `PlatformNotConfiguredException`.

Un 429 non è un errore della richiesta (T7). Il client aspetta da solo un `Retry-After` fino a 10 secondi e ripete la richiesta; uno più lungo, o senza un numero di secondi, è una `PlatformRequestException` con `isThrottled()` e i secondi in `->retryAfter` (60 se l'header manca). I job delle outbox rimettono la riga in coda dopo quel tempo, senza contarlo come un errore.

## Sistemi non Laravel

Il contratto è HTTP; il pacchetto è una comodità. La firma di un webhook si verifica così:

```text
firma = hex( HMAC-SHA256( PLATFORM_SIGNING_SECRET, X-Platform-Timestamp + "." + corpo grezzo ) )
```

`X-Platform-Signature` contiene una o due firme `v1=<hex>` separate da virgola: basta che una coincida. Va rifiutato con 401 un timestamp distante più di 5 minuti.

## Versioni

La major del pacchetto cambia a ogni rottura del contratto: un campo che sparisce, uno che diventa obbligatorio, una regola più stretta. Le versioni degli schemi di un tipo di evento si aggiungono e non cambiano mai: quelle fissate in `resources/schemas` sono confrontate con il portale da un contract test.

L'API pubblica è quella che il README descrive. Quello che porta `@internal` lo usa solo il pacchetto e può cambiare in una minor, senza avviso: un test lo tiene allineato.

## Sviluppo

```bash
composer lint            # Pint e PHPStan al livello max
composer test            # Pest
composer test:coverage   # copertura al 100%, con PCOV o Xdebug
```
