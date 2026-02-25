// expect: 0
// toolchain: true
// libs: m
#include <stdio.h>
#include <string.h>
#include <math.h>
#include <stdlib.h>

int main() {
    // String operations
    char buf[256];
    memset(buf, 0, 256);
    strcpy(buf, "Hello");
    strcat(buf, " World");

    // Memory operations
    double d = 3.14159;
    double m1 = d * 2.0;
    double m2 = d * 3.0;
    double m3 = m1 + m2;

    // IO operations
    char b2[64];
    snprintf(b2, 64, "value=%f", m3);

    // Math function calls
    double x = sin(1.0);
    x = cos(x);
    x = tan(x);
    x = sqrt(x * x + 1.0);
    x = pow(x, 2.0);
    x = log(x);
    x = exp(x);
    x = floor(x);
    x = ceil(x);
    x = fabs(-5.5);

    printf("buf=%s b2=%s x=%f\n", buf, b2, x);
    return 0;
}
