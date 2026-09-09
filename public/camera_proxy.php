<?php
/**
 * Camera Snapshot & Stream Proxy
 * ?ch=N  -> JPEG snapshot for camera channel N (via ffmpeg)
 * ?info=N -> JSON stream info for camera N
 * ?batch  -> JSON list of all cameras with snapshot URLs
 * ?live=N -> HTML page with embedded go2rtc live player
 */

// Camera RTSP stream mapping
$CAMERAS = [];
for ($i = 1; $i <= 23; $i++) {
    $CAMERAS[$i] = [
        'id' => $i,
        'code' => 'CAM-' . str_pad((string)$i, 2, '0', STR_PAD_LEFT),
        'rtsp' => 'rtsp://admin:IndoGH_432@192.168.0.212:554/Streaming/channels/' . $i . '01',
        'snapshot_url' => '?page=snapshot&ch=' . $i,
        'go2rtc' => 'http://localhost:1984/api/webrtc?src=cam' . str_pad((string)$i, 2, '0', STR_PAD_LEFT)
    ];
}

// Snapshot endpoint
if (isset($_GET['ch'])) {
    header('Content-Type: image/jpeg');
    header('Cache-Control: no-cache');
    $ch = intval($_GET['ch']);
    if (!isset($CAMERAS[$ch])) {
        http_response_code(404);
        echo json_encode(['error' => 'Invalid channel']);
        exit;
    }
    $url = $CAMERAS[$ch]['rtsp'];
    $tmpfile = tempnam(sys_get_temp_dir(), 'snap_') . '.jpg';
    // ffmpeg: grab single frame, fast timeout
    $cmd = sprintf(
        'ffmpeg -rtsp_transport tcp -i %s -frames:v 1 -q:v 5 -f image2 %s -y 2>/dev/null',
        escapeshellarg($url),
        escapeshellarg($tmpfile)
    );
    exec($cmd, $output, $ret);
    if ($ret === 0 && file_exists($tmpfile) && filesize($tmpfile) > 500) {
        header('Content-Length: ' . filesize($tmpfile));
        readfile($tmpfile);
        @unlink($tmpfile);
    } else {
        @unlink($tmpfile);
        // Return placeholder
        header('Content-Type: image/svg+xml');
        echo '<svg xmlns="http://www.w3.org/2000/svg" width="320" height="180" viewBox="0 0 320 180"><rect fill="#2d3436" width="320" height="180" rx="12"/><text fill="#636e72" font-family="sans-serif" font-size="14" text-anchor="middle" x="160" y="95">Camera ' . $ch . ' - No Signal</text></svg>';
    }
    exit;
}

// Stream info
if (isset($_GET['info'])) {
    header('Content-Type: application/json');
    $ch = intval($_GET['info']);
    if (isset($CAMERAS[$ch])) {
        echo json_encode(['ok' => true] + $CAMERAS[$ch]);
    } else {
        echo json_encode(['error' => 'Invalid channel']);
    }
    exit;
}

// Batch cameras list
if (isset($_GET['batch'])) {
    header('Content-Type: application/json');
    echo json_encode(['ok' => true, 'cameras' => array_values($CAMERAS)]);
    exit;
}

// Live player page
if (isset($_GET['live'])) {
    $ch = intval($_GET['live']);
    $cam = $CAMERAS[$ch] ?? null;
    if (!$cam) { echo 'Invalid channel'; exit; }
    ?>
    <!DOCTYPE html>
    <html><head><meta charset="utf-8"><title><?= $cam['code'] ?> Live</title>
    <style>body{margin:0;background:#1a1a2e;display:flex;align-items:center;justify-content:center;height:100vh;font-family:sans-serif;color:#fff}
    video{max-width:100%;max-height:100%;border-radius:12px;box-shadow:0 8px 32px rgba(0,0,0,0.5)}
    .info{position:fixed;top:20px;left:20px;background:rgba(0,0,0,0.7);padding:12px 20px;border-radius:12px}
    .close{position:fixed;top:20px;right:20px;background:rgba(214,48,49,0.9);border:none;color:#fff;padding:8px 16px;border-radius:8px;cursor:pointer;font-size:14px}</style></head>
    <body>
    <div class="info"><strong><?= $cam['code'] ?></strong> · Live Stream</div>
    <button class="close" onclick="history.back()">✕ Close</button>
    <video id="video" autoplay muted playsinline controls></video>
    <script src="/go2rtc/webrtc.js"></script>
    <script>
    const pc = new RTCPeerConnection({sdpSemantics:'unified-plan'});
    pc.addTransceiver('video', {direction:'recvonly'});
    pc.addTransceiver('audio', {direction:'recvonly'});
    pc.ontrack = e => { document.getElementById('video').srcObject = e.streams[0]; };
    pc.onicecandidate = e => {
        if (e.candidate) fetch('/go2rtc/api/webrtc?src=<?= $cam['code'] === 'CAM-01' ? 'cam01' : ($cam['code'] === 'CAM-02' ? 'cam02' : 'cam' . str_pad($ch, 2, '0', STR_PAD_LEFT)) ?>', {
            method:'POST', body: JSON.stringify({type:'call',sdp:pc.localDescription})
        }).then(r=>r.json()).then(d=>{ if(d.type==='answer') pc.setRemoteDescription(new RTCSessionDescription(d)); });
    };
    pc.createOffer().then(o=>{ pc.setLocalDescription(o); });
    </script></body></html>
    <?php
    exit;
}

echo json_encode(['error' => 'Missing parameters. Use ?ch=N, ?info=N, ?batch, or ?live=N']);
?>
