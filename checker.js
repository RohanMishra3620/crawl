const axios = require("axios");
const fs = require("fs");

const API_KEY = "7733b280-737d-11f1-9896-4d3f53f97fc9";

(async () => {

    const domains = fs.readFileSync("urls.txt", "utf8")
        .split(/\r?\n/)
        .map(x => x.trim())
        .filter(Boolean);

    for (const domain of domains) {

        console.log(`Checking: ${domain}`);

        try {

            const response = await axios.get(
                "https://app.zenserp.com/api/v2/search",
                {
                    headers: {
                        apikey: API_KEY
                    },
                    params: {
                        q: `site:${domain}`,
                        gl: "us",
                        hl: "en"
                    }
                }
            );

            const results = response.data.organic || [];

            const indexed = results.some(result => {

                try {
                    const hostname = new URL(result.url).hostname;
                    return hostname.includes(
                        domain.replace(/^https?:\/\//, "")
                    );
                } catch {
                    return false;
                }

            });

            console.log(indexed ? "✅ Indexed" : "❌ Not Indexed");

        } catch (err) {

            if (err.response) {
                console.log("API Error:", err.response.data);
            } else {
                console.log(err.message);
            }

        }

        console.log("----------------------------");

    }

})();