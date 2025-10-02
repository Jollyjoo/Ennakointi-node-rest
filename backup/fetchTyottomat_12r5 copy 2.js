// Toimiva versio 1.1 - kirjoittaa kantaan jotain ja tulostaa konsoliin kuukaudet ja arvot
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

            // Display all non-nested data
            let text = "";
            for (const x in myObj) {
                text += x + ": ";  
                text += myObj[x] + "\n";
            }

            // Parse specific values according to the structure
            // Extract months into an array
            const monthArray = [];
            for (const prop in myObj.dimension.Kuukausi.category.label) {
                monthArray.push(prop);
            }
            text += monthArray + "\n\n";

            // Display parsed months
            for (const prop in myObj.dimension.Kuukausi.category.label) {
                text += prop + "\n";
            }
            text += "\n";

            // Display parsed values
            for (let i in myObj.value) {
                text += monthArray[i] + ": "; // Use monthArray for months
                text += myObj.value[i] + "\n";
            }

            // Test it
            console.log(text);

            // Write to database
            await writeToDatabase(data);
        } else {
            console.error(`Failed to fetch data. Status code: ${response.status}`);
        }
    } catch (error) {
        console.error('Error fetching data:', error.message);
    }
}

// Function to write data to Azure SQL Database
async function writeToDatabase(data) {
    try {
        // Connect to Azure SQL Database
        await sql.connect(sqlConfig);
        console.log('Connected to Azure SQL Database');

        // Insert data into the database
        const request = new sql.Request();
        const query = `
            INSERT INTO Tyonhakijat (tyottomat_hakijat, kaikki_hakijat, alle_25_hakijat) 
            VALUES (@value1, @value2, @value3)
        `; // Replace with your actual table and column names

        // Example: Insert the first value from the data
        const value1 = data.value[0] ? parseInt(data.value[0], 10) : 0;
        const value2 = data.value[1] ? parseInt(data.value[1], 10) : 0;
        const value3 = data.value[2] ? parseInt(data.value[2], 10) : 0;

        console.log('Inserting values:', value1, value2, value3); // Debugging

        request.input('value1', sql.Int, value1);
        request.input('value2', sql.Int, value2);
        request.input('value3', sql.Int, value3);

        const result = await request.query(query);
        console.log('Data written to database:', result.rowsAffected);
    } catch (error) {
        console.error('Error writing to database:', error);
    } finally {
        await sql.close();
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
