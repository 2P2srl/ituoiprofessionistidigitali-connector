# Istruzioni per agenti AI: connettore di I tuoi professionisti digitali

Regole per chi scrive codice che usa `ituoiprofessionistidigitali/connector` in un sistema collegato al portale. Il contratto completo è `docs/contratto.md` nel repo del portale: il portale ne è l'autorità e il sistema si adegua, mai il contrario.

## Non aggirare il pacchetto

- Parla con il portale solo con `Platform::…` (o `PlatformClient`). Mai una `Http::post()` a mano verso `/api/v1`: token, nuovi tentativi, validazione ed errori stanno nel client.
- Costruisci gli eventi con `EnvelopeData::make()` o, per rispondere, con `$evento->reply()`. Non comporre la busta come array.
- Non inventare tipi di evento, versioni o campi del payload: esistono solo quelli di `Platform::eventTypes()`. Un tipo nuovo nasce nel portale, con una pull request, prima che un sistema lo mandi.
- Non usare classi o metodi marcati `@internal`: li usa solo il pacchetto e cambiano senza avviso. Delle outbox usa `ProfessionalOutbox::declare()`, `TransactionOutbox::payloadOf()` e `isRecorded()`.

## Tipologie

- Non dare mai per scontato `commercialisti`. Le tipologie sono un catalogo (`Platform::typologies()`): un codice è un dato, non una costante sparsa nel codice.
- Un aderente può avere più tipologie. Ogni evento dichiara la tipologia dello scambio, che dev'essere comune a mittente e destinatario.

## Aderenti

- `Platform::syncMembers()` vuole l'elenco **completo** degli aderenti del sistema: chi manca diventa inattivo. Non mandare mai un elenco parziale, né «solo quello cambiato».
- `external_ref` è il tuo riferimento stabile e opaco: non cambiarlo e non riusarlo per un altro soggetto.
- Salva l'`id` che il portale restituisce per ogni aderente: è il `sender` dei tuoi eventi. Gli id degli altri sistemi arrivano da `Platform::searchMembers()` o dagli eventi ricevuti.
- `listed: false` tiene l'aderente fuori dalla ricerca, ma non dagli scambi.
- Partita IVA e codice fiscale vanno in maiuscolo, senza spazi: il pacchetto e il portale verificano anche il carattere di controllo.
- `syncMembers()` non ha rifiuti da ritentare: due chiamate contemporanee vanno in fila e vince l'ultima (M6). Un 422 si corregge.
- Un aderente di un altro sistema si legge per id con `Platform::member($id)` (M16), per esempio il committente di un'offerta ricevuta (`$envelope->sender`). `MemberNotFoundException` è un 404 che non dice perché: non ritentare.

## Mandare eventi

- Il `sender` è sempre un aderente **tuo**: il portale ricava il sistema dalle credenziali e rifiuta un mittente altrui.
- Salva la busta prima di mandarla. Se l'invio va in timeout, rimanda **la stessa busta**, con lo stesso `event_id`: il portale risponde 200 senza duplicare. Una busta nuova con un `event_id` nuovo è un evento nuovo.
- Un 409 vuol dire che hai riusato un `event_id` con un contenuto diverso: è un errore nel codice, non qualcosa da ritentare.
- Manda gli eventi fra studi con `resolve(EventOutbox::class)->queue($busta)`, nella stessa transazione del database del cambio che li produce, non con `Platform::send()` a mano: l'outbox salva la busta, la manda dopo il commit e la ritenta identica. Una riga `failed` di `platform_event_outbox` è un errore nel codice: leggi `last_error`.
- I file di un evento passano dall'outbox: `OutgoingFileData::make($disco, $percorso, $nome, $tipo)`, cita il suo `ref` nel payload e passa i file a `EventOutbox::queue($busta, $file)`. Non chiamare `Platform::uploadFile()` a mano per un evento in outbox, e non cambiare un file dopo `queue()`: la riga diventa `failed`.
- I file ricevuti li scarica, verifica e conferma il pacchetto prima del listener: leggili da `$event->files` (`ReceivedFileData`, `disk` e `path`) e spostali nello storage del sistema. Non chiamare `Platform::downloadFile()` né `confirmFile()` per un evento del webhook. Un file con `isMissing()` va chiesto di nuovo al mittente. Non scrivere mai nel log il nome o il contenuto di un file.
- Nei test di ogni action che manda un evento verifica la busta con `PlatformOutbox::assertEventQueued($tipo, $callback)`, e con `assertEventNotQueued($tipo)` che non parta.

## Ricevere eventi

- La rotta del webhook la registra il pacchetto (`PLATFORM_WEBHOOK_PATH`, nome `platform.webhook`) e verifica la firma da sola. Non scriverne un'altra e non togliere il middleware della firma.
- La verifica del webhook (il `platform.ping` senza mittente) e la risposta ai `platform.ping` degli aderenti le gestisce il pacchetto. Non rispondere a mano.
- Ascolta `PlatformEventReceived`. Il listener:
  - va **in coda** (`ShouldQueue`): il portale aspetta al massimo dieci secondi;
  - è **idempotente su `event_id`**: lo stesso evento può arrivare due volte, quindi salva gli id elaborati con un indice unico;
  - non conta sull'ordine: usa `occurred_at` e `correlation_id`;
  - ignora i tipi che non gestisce, senza eccezioni.
- Un'eccezione in un listener sincrono fa rispondere 500 e il portale riprova: va bene solo se il lavoro non è stato salvato.
- Da sé il portale manda solo il `platform.ping` della verifica. Ogni altro evento senza mittente viene da un portale più recente: ha 202 e un `warning` nel log, e non arriva ai listener. È il segno che il pacchetto va aggiornato. Non intercettarlo a mano.

## Affidamento a uno studio di un altro sistema

- Non ci sono inserzioni, candidature né scelte sul portale: la controparte la sceglie sempre il sistema del committente. Uno studio di un altro sistema si trova con `Platform::searchMembers()` e si affida con `CounterpartyData::member($idAderente)`.
- Il percorso, con A il committente e B lo studio:
  1. A salva l'invio `invited` e, nella stessa transazione del database e **dopo** il salvataggio, accoda `coworking.assignment_offered` con `AssignmentOfferedData` e l'id `$proposta->platformTransactionId()`.
  2. B riceve l'offerta con `$event->transaction` (`RecordedTransactionData`): i termini sono quelli del registro, non chiederli altrove. Lo studio decide nel sistema; B accoda **una sola** risposta, `coworking.assignment_accepted` (`AssignmentAcceptedData`) o `coworking.assignment_declined` (`AssignmentDeclinedData`, con `reason` facoltativo), con `answered_at`.
  3. A registra `accepted` o `declined` **solo** nel listener della risposta di B, con `responded_at` uguale ad `answered_at` e la controparte identica all'invio (R24): prima della risposta il portale lo rifiuta.
- Il listener dell'offerta è in coda e idempotente sull'`event_id` e sull'id della transazione: crea in B quello che serve allo studio. Il committente lo legge con `Platform::member($envelope->sender)`. B non registra mai la transazione: è di A.
- A può revocare finché l'incarico non è concluso, prima o dopo la risposta: salva l'invio `revoked` con `closed_at` e accoda `coworking.assignment_revoked` (`AssignmentRevokedData`) con `revoked_at` uguale a `closed_at`. In B il listener della revoca chiude il lavoro. Una risposta di B arrivata dopo la revoca è un 422 sulla riga dell'outbox di B: l'offerta non c'è più, non ritentare.
- L'ordine lo tiene l'outbox: un evento che cita una transazione della outbox delle transazioni parte solo dopo che il portale ne ha confermato l'ultima versione. Non mandare eventi fra studi con `Platform::send()` e non aspettare la conferma a mano.
- Un consulente registrato sul portale si trova con `Platform::searchProfessionals()` (senza contatti) e si legge con `Platform::professional($id)` solo quando lo studio sta per affidargli l'incarico: il portale registra ogni lettura (N5). `$consulente->toCounterparty()` dà la controparte persona. I contatti non vanno in cache, in un log o fuori dall'anagrafica creata per l'incarico. `ProfessionalNotFoundException` vuol dire non più consultabile: non ritentare.
- Un `tax_code_verified: false` vuol dire codice fiscale dichiarato dal consulente, con il solo controllo formale: se lo colleghi a un'anagrafica del sistema per codice fiscale e l'email non coincide, chiedi conferma allo studio.

## Eventi fra studi

- I sette `coworking.*` si costruiscono con il loro DTO e `->toEnvelope($mittente, $destinatario, $tipologia)`, che mette `correlation_id` all'id della transazione e controlla lo schema; poi `EventOutbox::queue()`. Mai la busta a mano.
- `assignment_offered`, `assignment_revoked` e `activities_assigned` (dopo la firma) li manda solo il committente; `assignment_accepted`, `assignment_declined` e `activity_status` solo lo studio; `contract_details` tutti e due, dopo l'accettazione (E13). Il committente riporta lo stato delle attività nei suoi `PUT`: lo studio scelto non registra nulla.
- Il cliente finale (`FinalClientData`) va solo in `activities_assigned`, dopo la firma dei documenti del coworking: mai prima, mai in un altro evento.

## Collegamento

- Senza `PLATFORM_URL`, `PLATFORM_CLIENT_ID` e `PLATFORM_CLIENT_SECRET` il sistema non è collegato: il client lancia `PlatformNotConfiguredException`. Controlla prima con `resolve(ConnectorConfig::class)->isConnected()` e spegni la funzione, invece di romperla.
- Dopo il deploy o quando cambia l'URL del webhook chiama `Platform::present(route('platform.webhook'))`. Finché la verifica non riesce, il sistema legge i cataloghi ma non pubblica aderenti né eventi (403 con `reason: pending`).

## Registro delle transazioni

- Ogni incarico inviato va registrato sul portale **quando nasce**, nello stato `invited`, e poi a ogni cambio, con la controparte che il sistema ha scelto: un collaboratore o un consulente persona, o un aderente di questo sistema o di un altro. Non è facoltativo: è la regola R9 del contratto. Ogni PUT è la fotografia completa della transazione, quindi il portale accetta la prima registrazione in qualunque stato, purché coerente con le sue date e con le sue attività: se l'invio di `invited` non è ancora riuscito quando il collaboratore accetta, l'outbox manda direttamente `accepted`, ed è corretto. Con un aderente di un altro sistema `accepted` e `declined` arrivano solo dopo la sua risposta (R24).
- Ogni transazione ha un `id` fisso, che l'outbox genera al primo salvataggio e manda a ogni revisione (R23): leggilo con `$model->platformTransactionId()` per citarlo negli eventi. Non generarlo e non salvarlo a mano.
- Una transazione è un invio con prezzi fermi: dopo la prima registrazione cambiano solo lo stato, le sue date e, per ogni attività, stato, minuti lavorati e chiusura (R13). Per cambiare un prezzo revoca l'attività e mandala in un invio nuovo, con un riferimento nuovo.
- Lo stato segue le attività (R14): `invited` e `declined` le hanno tutte `open`; `accepted` almeno una `open`; `completed` nessuna `open` e almeno una `completed`; `revoked` nessuna `open` e nessuna `completed`. Un invio ritirato prima della risposta è `revoked`, con tutte le attività `revoked`.
- `signed_at` è la data della firma dell'incarico, registrata dal sistema (R8, R22): `null` in `invited` e `declined`, facoltativa in `accepted` (`null` = in attesa di firma) e in `revoked`, obbligatoria in `completed`, fra `responded_at` e `closed_at`. Nessuno lavora prima della firma: senza firma nessuna attività `completed` e minuti lavorati `null` o `0`; un'attività `completed` non chiude prima della firma. Una firma mandata non torna `null`.
- I minuti previsti sono obbligatori su ogni attività, anche a ore: il totale c'è sempre. I minuti lavorati si mandano solo su un'attività chiusa con una controparte persona: con un aderente sono sempre `null`.
- Costruisci la transazione con i DTO tipizzati (`TransactionData`, `CounterpartyData`, `TransactionActivityData`, `CompensationData`, `ActivityDescriptionData`, `ProcessData`), mai con un array a mano. La mappatura dai modelli del sistema sta in una sola classe del sistema: il pacchetto non conosce i tuoi modelli.
- Una controparte persona porta l'anagrafica del professionista: codice fiscale, nome, cognome ed email obbligatori; partita IVA, comune e provincia se il sistema li ha, altrimenti `null`. Senza email `CounterpartyData::person()` non si costruisce e `toPlatformTransaction()` lancia un errore al salvataggio dell'invio: chiedi l'email del collaboratore prima di affidargli un incarico. Il DTO porta l'email in minuscolo (T6). È la fotografia dell'invio: identica a ogni revisione, anche se poi l'anagrafica cambia (R13, un cambio è un 422). Un aderente porta il suo `member_id`, con i campi della persona a `null`: la controparte ha sempre le nove chiavi.
- `counterparty` non è mai `null`. `title` e `description` sono obbligatori in ogni stato.
- Tutte le chiavi ci sono sempre, anche a `null`: testata, controparte, attività, compenso e descrizione. Costruisci con i DTO, che le emettono tutte.
- La parte ferma all'invio (controparte, tipologia, testi, prezzi) la costruisci da una fotografia salvata all'invio, non dai dati di oggi.
- Il modello dell'invio implementa `RecordsPlatformTransaction` e usa il trait `RecordsOnPlatform`; `platformTransactionReference()` è `null` per una bozza, che non si registra. I modelli che cambiano l'invio senza esserlo (le righe, l'incarico che lo contiene) implementano `AffectsPlatformTransactions` e usano `RecordsAffectedOnPlatform`. Il pacchetto scrive ogni salvataggio in una outbox, nella stessa transazione del database, e lo manda in coda con la revisione giusta. Non chiamare `Platform::recordTransaction()` a mano per questi modelli.
- La registrazione segue il lavoro, non lo blocca: l'affidamento è operativo da subito e l'outbox lo comunica dopo. `$model->isRecordedOnPlatform()` serve a mostrare se il portale ha confermato l'ultima versione, non a fermare il lavoro.
- Una transazione non si cancella mai sul portale (R12): prima di eliminare un affidamento portalo a `revoked`, con `closed_at`, e salvalo; poi eliminalo. L'outbox manda la versione salvata anche dopo il delete.
- Mai aggiornamenti di massa (`Model::query()->update()`, `DB::table()->update()`) sui campi che finiscono in `toPlatformTransaction()`: saltano il modello e l'outbox. Per quei campi aggiorna riga per riga. Sui campi che non fanno parte della transazione, come l'ultimo accesso del collaboratore, l'aggiornamento di massa va bene.
- Il comando `platform:send-outbox` gira da solo ogni cinque minuti e rimanda le versioni rimaste indietro: serve che lo scheduler di Laravel sia attivo.
- Ogni attività porta il suo processo, `ProcessData`, obbligatorio: `reference` è un identificativo opaco del sistema, mai un nome, stabile per ogni istanza del soggetto (processo di linea o di staff, progetto, agenda) e uguale per tutte le sue attività; `name` è il nome del catalogo, o `null`.
- La descrizione `assignment` di ogni attività porta solo i nomi del modello di processo e dell'attività del catalogo dello studio, oppure `null`: **mai** il nome, il codice fiscale o la partita IVA del cliente, il nome di un'agenda o di un'area di progetto, né un testo libero.
- R6: niente testo libero nelle descrizioni delle attività; `title` e `description` della testata sono testi dello studio, senza dati del cliente: `title` testo semplice fino a 255 caratteri, `description` Markdown fino a 10000.
- Lo storico già esistente si carica una volta con `Platform::recordTransactions()`, fino a 500 per chiamata. Segue le stesse regole della PUT (R10), senza eccezioni. Il pacchetto dà a ognuna il suo `id` prima della richiesta; le transazioni registrate entrano nella outbox come confermate, le altre come da mandare, e `platform:send-outbox` le manda una per una. Non caricarle in un altro modo, o le loro persone restano fuori dalle dichiarazioni dell'anagrafica.
- Una riga dell'outbox in stato `failed` è un errore nel codice (contratto violato o conflitto di revisione): leggi `last_error` e correggi, non ritentare alla cieca.
- Un test di architettura nel progetto rende l'obbligo verificabile:

```php
arch('assignments reach the register of the platform')
    ->expect(App\Models\P2pExternalAssignmentProposal::class)
    ->toImplement(ITuoiProfessionistiDigitali\Connector\Contracts\RecordsPlatformTransaction::class)
    ->toUseTrait(ITuoiProfessionistiDigitali\Connector\Concerns\RecordsOnPlatform::class);
```

- Nei test di ogni action che tocca un invio, le sue righe o l'incarico, verifica la comunicazione con `ITuoiProfessionistiDigitali\Connector\Testing\PlatformOutbox::assertRecorded($proposta)`, e con `assertNotRecorded()` che una bozza non parta.

## Anagrafica dei professionisti

- `declare()` non manda nulla per una persona che la outbox delle transazioni non ha mai visto, e non ne controlla nemmeno i dati: chiamala a ogni cambio, senza filtri tuoi, anche per un referente senza email (passa `email: ''`). Per una persona incaricata, invece, dati non validi sono una `ValidationException`.
- Quando l'anagrafica di un collaboratore persona cambia nel sistema, dichiarala con `resolve(ProfessionalOutbox::class)->declare($codiceFiscale, new ProfessionalRecordData(...))`, nella stessa transazione del database del cambio. `declared_at` è il momento del cambio, non quello dell'invio.
- L'anagrafica è sempre completa: un campo facoltativo a `null` toglie il dato. Non mandare solo i campi cambiati. L'email è obbligatoria e va in minuscolo (T6), come nella controparte persona.
- Non chiamare `Platform::declareProfessional()` a mano: l'outbox ripete il 404 finché ci sono transazioni alla persona non ancora registrate, e senza la ripetizione il cambio va perso (R18).
- Una dichiarazione `discarded` non è un errore: il sistema non ha incaricato quella persona, o il codice fiscale è stato corretto. Il professionista nasce dal prossimo incarico.
- Le transazioni non si toccano per un cambio dell'anagrafica: ognuna porta quella del suo invio, identica a ogni revisione (R13).

## Errori

- `422`: il contratto è violato. Non ritentare: leggi `->errors`, le cui chiavi sono i campi in notazione puntata.
- `401`: il client rinnova il token da solo. Non mettere in cache il token a mano.
- `403`: leggi `->reason` (`pending`, `suspended`, `revoked`). È una questione di collegamento, da sistemare con l'operatore del portale, non nel codice.
- `5xx` ed errori di rete: il client riprova da solo qualche volta; dopo, la stessa busta si può rimandare.
- `429` (T7): il client aspetta da solo un `Retry-After` breve e ripete; uno più lungo è una `PlatformRequestException` con `isThrottled()` e i secondi in `->retryAfter`, da ripetere dopo quel tempo. Le outbox lo fanno da sole: rimettono il job in coda dopo `Retry-After`.

## Segreti e configurazione

- Client secret e segreto di firma stanno solo nel `.env` del server: mai nel frontend, in una vista o in un log.
- Leggi la configurazione con `config('platform.*')` o `ConnectorConfig`, mai con `env()` nel codice applicativo.
