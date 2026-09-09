{{-- The document `whatsapp:test` sends. Deliberately plain: it is here to prove the engine renders
     and the provider accepts, so anything that could fail for its own reasons is left out. --}}
<!DOCTYPE html>
<html lang="en">
<head><meta charset="utf-8"><title>Delivery test</title></head>
<body style="font-family: DejaVu Sans, sans-serif; padding: 40px; color: #14532d;">
    <h1 style="font-size: 20px; margin: 0 0 12px;">{{ $application }}</h1>
    <p style="font-size: 13px; margin: 0 0 4px;">This is a test document.</p>
    <p style="font-size: 13px; margin: 0; color: #4b5563;">Rendered {{ $sentAt }}.</p>
</body>
</html>
