const express = require("express");
const fs = require("fs");
const cors = require("cors");
const app = express();

app.use(cors());
app.use(express.json());

const evalFile = "./evaluations.json";

// --- Evaluations ---
app.get("/evaluations", (req, res) => {
  fs.readFile(evalFile, "utf8", (err, data) => {
    if (err) return res.status(500).json({ error: "Failed to read data" });
    res.json(JSON.parse(data || "[]"));
  });
});

app.post("/evaluations", (req, res) => {
  const newEval = req.body;
  fs.readFile(evalFile, "utf8", (err, data) => {
    if (err) return res.status(500).json({ error: "Failed to read file" });

    let evaluations = JSON.parse(data || "[]");
    evaluations.push(newEval);

    fs.writeFile(evalFile, JSON.stringify(evaluations, null, 2), (err) => {
      if (err) return res.status(500).json({ error: "Failed to save data" });
      res.json({ success: true, message: "Evaluation saved" });
    });
  });
});

// Users endpoints removed: user management must be database-driven in PHP app

app.listen(3000, () => {
  console.log("Server running on http://localhost:3000");
});
