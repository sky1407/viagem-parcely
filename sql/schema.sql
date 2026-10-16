CREATE EXTENSION IF NOT EXISTS postgis;

CREATE TABLE land_type (
    code smallint PRIMARY KEY,
    name text NOT NULL
);

CREATE TABLE land_use (
    code smallint PRIMARY KEY,
    name text NOT NULL
);

CREATE TABLE protection_type (
    code smallint PRIMARY KEY,
    name text NOT NULL
);

CREATE TABLE municipality (
    code integer PRIMARY KEY,
    name text NOT NULL
);

CREATE TABLE cadastral_area (
    code integer PRIMARY KEY,
    name text NOT NULL,
    municipality_code integer NOT NULL REFERENCES municipality (code)
);

CREATE TABLE parcel (
    id bigint PRIMARY KEY,
    cadastral_area_code integer NOT NULL REFERENCES cadastral_area (code),
    numbering_type smallint NOT NULL CHECK (numbering_type IN (1, 2)),
    stem_number integer NOT NULL,
    subdivision_number integer,
    area_m2 integer NOT NULL CHECK (area_m2 >= 0),
    land_type_code smallint NOT NULL REFERENCES land_type (code),
    land_use_code smallint REFERENCES land_use (code),
    valid_from timestamp NOT NULL,
    geom geometry(MultiPolygon, 5514) NOT NULL,
    geom_3857 geometry(MultiPolygon, 3857) GENERATED ALWAYS AS (ST_Transform(geom, 3857)) STORED
);

CREATE TABLE parcel_protection (
    parcel_id bigint NOT NULL REFERENCES parcel (id) ON DELETE CASCADE,
    protection_code smallint NOT NULL REFERENCES protection_type (code),
    PRIMARY KEY (parcel_id, protection_code)
);

CREATE INDEX parcel_geom_3857_gist ON parcel USING gist (geom_3857);
CREATE INDEX parcel_cadastral_area_idx ON parcel (cadastral_area_code);
