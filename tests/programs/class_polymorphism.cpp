// expect: 85
struct Shape {
    int id;

    virtual int area() {
        return 0;
    }

    int getId() {
        return id;
    }
};

struct Rectangle : Shape {
    int width;
    int height;

    int area() {
        return width * height;
    }
};

struct Square : Shape {
    int side;

    int area() {
        return side * side;
    }
};

int computeTotal(Shape* s1, Shape* s2, Shape* s3) {
    return s1->area() + s2->area() + s3->area();
}

int main() {
    Rectangle* r = new Rectangle();
    r->id = 1;
    r->width = 5;
    r->height = 3;

    Square* sq = new Square();
    sq->id = 2;
    sq->side = 7;

    Shape* base = new Shape();
    base->id = 3;

    // Virtual dispatch: 15 + 49 + 0 = 64
    int total = computeTotal(r, sq, base);

    // Non-virtual method on derived: 1 + 2 + 3 = 6
    int ids = r->getId() + sq->getId() + base->getId();

    // Direct call: 3
    int direct = r->area() - sq->area() + sq->area();

    delete r;
    delete sq;
    delete base;

    return total + ids + direct;
}
