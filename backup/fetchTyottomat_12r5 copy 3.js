// Toimiva versio 1.2 kirjoittaa kantaa jo paljon enemmän dataa ja tulostaa konsoliin kuukaudet ja arvot
// Ottaa .json tiedostosta tilastokeskuksen muotoiluvaatimukset.
// Lähettää post-metodilla kyselyn stat.
// Ottaa datan vastaan ja parsii kuukaudet ja arvot.
// ajastus esim. minuutin välein

const fs = require('fs').promises;
const axios = require('axios');
const path = require('path');
const sql = require('mssql');

// Tilastokeskuksen API endpoint tilastolle
const apiUrl = 'https://pxdata.stat.fi:443/PxWeb/api/v1/fi/StatFin/tyonv/statfin_tyonv_pxt_12r5.px';
const interval = 600000; // 16,66 tuntia -ajoitus uuden tilaston hakuun (millisekuntia)
const jsonfile = path.join(__dirname, '_tyottomat_12r5.json'); //Tiedostoon määritelty STAT muotoilu halutuista tiedoista

// Azure SQL Database configuration
const sqlConfig = {
    user: 'Christian', // Replace with your Azure SQL Database username
    password: 'Ennakointi24', // Replace with your Azure SQL Database password
    server: 'ennakointi-srv.database.windows.net', // Replace with your Azure SQL Database server
    database: 'EnnakointiDB', // Replace with your Azure SQL Database name
    options: {
        encrypt: true, // Use encryption
        enableArithAbort: true
    }
};

// Function to read the JSON file
async function getData(jsonfile) {
    try {
        const data = await fs.readFile(jsonfile, 'utf8');
        return JSON.parse(data);
    } catch (error) {
        console.error('Error reading JSON file:', error);
        throw error;
    }
}

// Function to fetch data from the API
async function fetchData(newStatData) {
    try {
        console.log('Fetching data from:', apiUrl); // Log the URL being fetched
        const response = await axios.post(apiUrl, newStatData, {
            headers: {
                'Content-Type': 'application/json; charset=utf-8'
            },
            timeout: 10000 // Increase timeout to 10 seconds
        });
        console.log('Response status:', response.status); // Log the response status
        if (response.status === 200) {
            const data = response.data;
            console.log('Fetched data:', data);

            // Parse the data
            const myObj = data;

            // Extract specific values to insert into the database
            const alueIndex = myObj.dimension.Alue.category.label;
            const kuukausiIndex = myObj.dimension.Kuukausi.category.index;
            const tiedotIndex = myObj.dimension.Tiedot.category.index;

            const alueKeys = Object.keys(alueIndex);
            const kuukausiKeys = Object.keys(kuukausiIndex);
            const tiedotKeys = Object.keys(tiedotIndex);

            let valueIndex = 0;

            // Connect to Azure SQL Database
            await sql.connect(sqlConfig);
            console.log('Connected to Azure SQL Database');

            for (const alue of alueKeys) {
                for (const kuukausi of kuukausiKeys) {
                    for (const tiedot of tiedotKeys) {
                        const value = myObj.value[valueIndex];
                        const alueName = alueIndex[alue];
                        const kuukausiDate = parseKuukausi(kuukausi);
                        const tiedotId = tiedotIndex[tiedot];

                        console.log(`Inserting alue: ${alueName}, kuukausi: ${kuukausiDate}, tiedot: ${tiedot}, value: ${value}`);

                        // Get alueId from maakunnat table
                        const alueId = await getAlueId(alueName);

                        // Write to database
                        await writeToDatabase(alueId, kuukausiDate, tiedotId, value);

                        valueIndex++;
                    }
                }
            }

            // Close the database connection
            await sql.close();
        } else {
            console.error(`Failed to fetch data. Status code: ${response.status}`);
        }
    } catch (error) {
        console.error('Error fetching data:', error.message);
    }
}

// Function to get alueId from maakunnat table
async function getAlueId(alueName) {
    try {
        const request = new sql.Request();
        const query = `
            SELECT maakunta_id FROM maakunnat WHERE maakunta = @alueName
        `;

        request.input('alueName', sql.VarChar, alueName);

        const result = await request.query(query);
        if (result.recordset.length > 0) {
            return result.recordset[0].id;
        } else {
            throw new Error(`Maakunta_ID not found for name: ${alueName}`);
        }
    } catch (error) {
        console.error('Error fetching maakunta_id:', error);
        throw error;
    }
}

// Function to parse kuukausi to date format
function parseKuukausi(kuukausi) {
    const year = parseInt(kuukausi.substring(0, 4), 10);
    const month = parseInt(kuukausi.substring(5, 7), 10);
    return new Date(year, month - 1, 1); // Month is 0-indexed in JavaScript Date
}

// Function to write data to Azure SQL Database
async function writeToDatabase(alueId, kuukausiDate, tiedotId, value) {
    try {
        // Insert data into the database
        const request = new sql.Request();
        const query = `
            INSERT INTO Tyonhakijat (maakunta_id, aika, tyottomat_hakijat, kaikki_hakijat) 
            VALUES (@alueId, @kuukausiDate, @tiedotId, @value)
        `; // Replace with your actual table and column names

        console.log('Inserting values:', alueId, kuukausiDate, tiedotId, value); // Debugging

        request.input('alueId', sql.Int, alueId);
        request.input('kuukausiDate', sql.Date, kuukausiDate);
        request.input('tiedotId', sql.Int, tiedotId);
        request.input('value', sql.Int, value);

        const result = await request.query(query);
        console.log('Data written to database:', result.rowsAffected);
    } catch (error) {
        console.error('Error writing to database:', error);
    }
}

// Main function to read the JSON file and fetch data
async function main() {
    try {
        const newStatData = await getData(jsonfile);
        await fetchData(newStatData);
    } catch (error) {
        console.error('Error in main function:', error);
    }
}

// Fetch data initially
main();

// Fetch data at regular intervals
setInterval(main, interval);
