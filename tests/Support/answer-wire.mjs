import http from 'node:http';
import { gzipSync } from 'node:zlib';
const receipt = JSON.stringify({
    id: 'resp_synthetic_chunked',
    status: 'completed',
    model: 'gpt-5.4-mini-2026-03-17',
    usage: { input_tokens: 10, output_tokens: 10, total_tokens: 20 },
    output: [
        {
            type: 'message',
            content: [
                {
                    type: 'output_text',
                    text: '{"claim":"cash.available","mode":"recorded","scope":"current"}',
                },
            ],
        },
    ],
});
const counts = {};
const protocols = [];
const server = http.createServer((req, res) => {
    if (req.url === '/counts') {
        res.end(JSON.stringify({ counts, protocols }));
        return;
    }
    counts[req.url] = (counts[req.url] ?? 0) + 1;
    protocols.push(req.httpVersion);
    req.resume();
    if (req.url === '/lost') {
        req.on('end', () => req.socket.destroy());
        return;
    }
    if (req.url === '/compressed') {
        res.writeHead(200, {
            'Content-Type': 'application/json',
            'Content-Encoding': 'gzip',
        });
        res.end(gzipSync(Buffer.from('x'.repeat(1000000))));
        return;
    }
    if (req.url === '/oversize') {
        res.writeHead(200, {
            'Content-Type': 'application/json',
            'Content-Length': '1000000',
        });
        res.end('x'.repeat(1000000));
        return;
    }
    res.writeHead(200, { 'Content-Type': 'application/json' });
    res.write(receipt.slice(0, 30));
    const timer = setTimeout(
        () => res.end(receipt.slice(30)),
        req.url === '/slow' ? 600 : 15,
    );
    res.on('close', () => clearTimeout(timer));
});
server.listen(0, '127.0.0.1', () =>
    process.stdout.write(String(server.address().port) + '\n'),
);
process.on('SIGTERM', () => server.close(() => process.exit(0)));
