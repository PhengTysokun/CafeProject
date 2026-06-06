const express = require("express");
const http = require("http");
const cors = require("cors");
const { Server } = require("socket.io");

const app = express();
app.use(cors());
app.use(express.json());

const server = http.createServer(app);
const io = new Server(server, {
    cors: { origin: "*" }
});

io.on("connection", socket => {
    console.log("🔌 Client connected:", socket.id);
    socket.on("disconnect", () => {
        console.log("❌ Client disconnected:", socket.id);
    });
});

// PHP → Node
app.post("/notify", (req, res) => {
    const { type, payload } = req.body;
    console.log("📡 Event:", type, payload);

    io.emit(type, payload);

    return res.json({ ok: true });
});

server.listen(3000, () => {
    console.log(" Real-time server running at http://localhost:3000");
});
